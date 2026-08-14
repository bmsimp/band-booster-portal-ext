<?php
namespace Civi\Boosterportal;

use Civi\Api4\Contact;

/**
 * Derives the LOGGED-IN parent's own family — their students, then the
 * billing household(s) those students belong to, then (for the §4.6
 * complete-set gate) each household's true complete active student count —
 * for GetMyBalance (Task 12, §4.6). No method here ever accepts a
 * caller-supplied contact/household id from outside this extension's own
 * already-ACL-verified output: studentsOf() takes a contact id but
 * immediately asserts it IS the session contact, and billingHouseholdsOf()
 * only ever receives students that studentsOf() itself already verified.
 * This is what keeps GetMyBalance's "no request parameter ever selects
 * whose family to look up" guarantee true one level down.
 *
 * --- Why this isn't the plan's literal join, and what happens if you try it ---
 *
 * The design plan's draft for GetMyBalance read:
 *
 *   Contact::get(TRUE)
 *     ->addJoin('Relationship AS rel', 'INNER', NULL,
 *       ['rel.contact_id_b', '=', 'id'], ['rel.contact_id_a', '=', $cid])
 *     ->addWhere('rel.is_permission_a_b', 'IN', [VIEW, EDIT])
 *     ->addWhere('Booster_QBO_Student.qbo_subcustomer_id', 'IS NOT NULL')
 *     ->execute();
 *
 * Tried verbatim against the AclLeakTest two-family fixture (logged in as
 * parent A, 'access CiviCRM' only): it returns ZERO rows, including for
 * parent A's OWN student. Root cause: joining to the Relationship entity
 * pulls in Relationship's own default ACL clause
 * (CRM_Core_DAO::addSelectWhereClause()), which requires BOTH
 * contact_id_a AND contact_id_b to independently pass the Contact ACL
 * check. contact_id_b (the student) passes — that's exactly what
 * boosterportal_civicrm_aclWhereClause() grants — but contact_id_a (the
 * parent, i.e. the logged-in user themself) does NOT: a portal parent has
 * no "view my own contact" grant under the minimal 'access CiviCRM'
 * permission set (Task 4), so their own contact row never independently
 * passes the ACL check the join re-applies. AclLeakTest's
 * testRelationshipsOfOtherFamilyAreInvisible() documents the identical
 * effect one level up: Relationship::get(TRUE) is always [], even scoped to
 * family A's own relationships — this is the SAME over-restriction, not a
 * new one, just now blocking a join that needs those rows to exist.
 *
 * The fix here is a TWO-LAYER approach, not a permission grant (granting
 * "view my own contact" would be a bigger, separately-reviewable ACL change
 * — Task 7's amendment flags it as a Task 17 decision, not this one):
 *
 *  1. Read CANDIDATE student contact ids via direct SQL on
 *     civicrm_relationship, mirroring boosterportal_civicrm_aclWhereClause()'s
 *     own subquery exactly: same relationship type (Portal_Parent_of), same
 *     direction (contact_id_a = the parent), same is_active/date-window
 *     filter, same is_permission_a_b whitelist (VIEW or EDIT). This step is
 *     NOT itself an ACL check — it's plain SQL re-deriving the identical
 *     candidate set the hook would grant, run with the ordinary DB
 *     connection, not through any ACL-checked API.
 *  2. Verify those exact candidate ids independently via a REAL,
 *     ACL-checked Contact::get(TRUE) call, filtered to just those ids. Only
 *     the INTERSECTION of (1) and (2) is ever returned. If the SQL mirror
 *     in (1) ever drifts from the hook in boosterportal.php (a future edit
 *     to one without the other), the result is a silent SUBSET, never a
 *     superset — this fails closed, not open.
 *
 * --- checkPermissions FALSE calls in this file, and their blast radius ---
 *
 * billingHouseholdsOf() and activeStudentCountOf() are the sanctioned
 * exceptions to invariant 2 (checkPermissions FALSE) in this extension's
 * parent-reachable code — see InvariantTest's allowlist entry on
 * FamilyResolver.php for the full containment argument. Post-Task-12
 * adversarial security review (C1/C2): the INPUT containment argument above
 * (studentsOf() is ACL-verified; billingHouseholdsOf() is keyed only off
 * that verified output) was correct but incomplete on its own — it bounded
 * WHICH students/households could be looked up, not what a caller was
 * allowed to DO with the resulting household-level figures once looked up.
 * BalanceService's complete-set gate (familyBalance()'s $completeHousehold
 * parameter) is what closes that: a household's aggregate (BalanceWithJobs)
 * is only ever readable when activeStudentCountOf() for that household
 * equals the verified count billingHouseholdsOf() produced for it — i.e.
 * the OUTPUT of this file is only used at household-aggregate granularity
 * when the caller could see every student that aggregate covers. See
 * AclLeakTest::testBillingHouseholdsOfDetectsIncompleteHouseholdWhenSiblingUnedged(),
 * ::testBillingHouseholdsOfResolvesSplitFamilyToStudentsOwnHousehold(), and
 * ::testBillingHouseholdsOfMarksTwoSeparateCompleteHouseholdsComplete() for
 * the fixtures that keep both halves (this file's grouping/counting, and
 * BalanceService's gate) honest together.
 */
class FamilyResolver {

  /**
   * @param int $contactId
   *   Must be the CURRENTLY logged-in contact — see class doc. Passed
   *   explicitly (rather than read from the session in here) so callers are
   *   forced to be honest about whose family this is, and so this method
   *   stays a pure function of its input for testing.
   * @return array<array{id: int, display_name: string, qbo_subcustomer_id: string}>
   *   $contactId's own students that carry a QBO sub-customer id. Empty
   *   array if none (never NULL — callers treat empty as "nothing to show").
   * @throws \CRM_Core_Exception
   *   If $contactId is not the logged-in contact — see class doc: this
   *   derivation's safety depends entirely on evaluating ACLs for the same
   *   user the candidate SQL was scoped to, and calling it for any other
   *   contact id is always a caller bug, never a legitimate use.
   */
  public static function studentsOf(int $contactId): array {
    $loggedIn = \CRM_Core_Session::getLoggedInContactID();
    if ((int) $loggedIn !== $contactId) {
      throw new \CRM_Core_Exception(
        'FamilyResolver::studentsOf() may only be called for the logged-in contact — '
        . 'its ACL verification step (Contact::get(TRUE)) is only meaningful for the '
        . 'session that is actually authenticated.'
      );
    }

    $portalTypeId = \CRM_Core_DAO::singleValueQuery(
      "SELECT id FROM civicrm_relationship_type WHERE name_a_b = 'Portal_Parent_of'"
    );
    if (!$portalTypeId) {
      // RelationshipType not installed — nothing to derive.
      return [];
    }

    // Layer 1: candidate ids via plain SQL, mirroring
    // boosterportal_civicrm_aclWhereClause()'s own subquery exactly (same
    // type, direction, is_active, permission whitelist, date window).
    $candidateIds = [];
    $dao = \CRM_Core_DAO::executeQuery(
      'SELECT r.contact_id_b AS student_id
       FROM civicrm_relationship r
       WHERE r.contact_id_a = %1
         AND r.relationship_type_id = %2
         AND r.is_active = 1
         AND r.is_permission_a_b IN (%3, %4)
         AND (r.start_date IS NULL OR r.start_date <= CURDATE())
         AND (r.end_date IS NULL OR r.end_date >= CURDATE())',
      [
        1 => [$contactId, 'Positive'],
        2 => [(int) $portalTypeId, 'Positive'],
        3 => [\CRM_Contact_BAO_Relationship::VIEW, 'Positive'],
        4 => [\CRM_Contact_BAO_Relationship::EDIT, 'Positive'],
      ]
    );
    while ($dao->fetch()) {
      $candidateIds[] = (int) $dao->student_id;
    }
    if (!$candidateIds) {
      return [];
    }

    // Layer 2: verify via a real, ACL-checked call — only the intersection
    // of "SQL says this is a candidate" and "the ACL layer actually agrees
    // this contact is visible" is trusted.
    $verified = Contact::get(TRUE)
      ->addWhere('id', 'IN', $candidateIds)
      ->addWhere('Booster_QBO_Student.qbo_subcustomer_id', 'IS NOT NULL')
      ->addSelect('id', 'display_name', 'Booster_QBO_Student.qbo_subcustomer_id')
      ->execute();

    $students = [];
    foreach ($verified as $row) {
      $students[] = [
        'id' => (int) $row['id'],
        'display_name' => $row['display_name'],
        'qbo_subcustomer_id' => $row['Booster_QBO_Student.qbo_subcustomer_id'],
      ];
    }
    return $students;
  }

  /**
   * Groups the given ACL-verified students by billing Household (§4.4 — the
   * QBO billing anchor; no ACL role of its own). A parent CAN legitimately
   * have verified students spread across more than one household (I2) — the
   * grouping is entirely driven by each STUDENT's own "Household Member of"
   * membership, never by the parent's, which is what correctly separates a
   * split-family student (living in a different household than the parent)
   * from the parent's own home household.
   *
   * checkPermissions FALSE (Task 7 amendment, restated): parents cannot see
   * Household rows at all — the aclGroup grant in boosterportal.php covers
   * only the Booster_QBO_Student custom group, and the aclWhereClause hook
   * only ever grants contact_id_b of a Portal_Parent_of edge, which is
   * never a Household. See InvariantTest's allowlist entry on
   * FamilyResolver.php, and the class-level doc above, for the full
   * containment argument (input scoping AND the BalanceService-side
   * complete-set gate that bounds what the output may be used for).
   *
   * R3 (second security review): the underlying data model doesn't
   * structurally prevent a single student contact from holding TWO
   * simultaneous active "Household Member of" edges (nothing here creates
   * that, but nothing prevents a hand-edit or import anomaly from doing
   * so either). Left alone, that student would be assigned to — and their
   * balance counted toward — BOTH households, double-counting real money.
   * A student is therefore assigned to exactly ONE household: whichever
   * has the LOWEST household id, deterministically (rows arrive ordered by
   * household id ascending, so "first seen" IS "lowest id" — see the loop
   * below). See
   * AclLeakTest::testBillingHouseholdsOfAssignsDuallyMemberedStudentToOneHouseholdOnly().
   *
   * @param array<array{id: int, display_name: string, qbo_subcustomer_id: string}> $students
   *   Must be studentsOf()'s own return for the same contact — never
   *   caller-supplied ids.
   * @return array<int, array{id: int, qbo_customer_id: string, students: array}>
   *   Keyed by household id, ordered by household id ascending
   *   (deterministic). Each entry's 'students' is the subset of $students
   *   verified as belonging to that household (each student appears in AT
   *   MOST ONE entry — see R3 above), ordered by student id. A household
   *   left with no students after that de-duplication is omitted entirely.
   */
  public static function billingHouseholdsOf(array $students): array {
    if (!$students) {
      return [];
    }
    $studentIds = array_column($students, 'id');
    $studentsById = [];
    foreach ($students as $s) {
      $studentsById[$s['id']] = $s;
    }

    $rows = Contact::get(FALSE)
      ->addWhere('contact_type', '=', 'Household')
      ->addWhere('Booster_QBO.qbo_customer_id', 'IS NOT NULL')
      ->addJoin('Relationship AS member', 'INNER', NULL,
        ['member.contact_id_b', '=', 'id'],
        ['member.contact_id_a', 'IN', $studentIds],
        // Join-condition values are field expressions by default in Api4;
        // the double-quoted inner string is what marks this as a SQL
        // string literal rather than a (nonexistent) field named
        // "Household Member of".
        ['member.relationship_type_id:name', '=', '"Household Member of"'],
        ['member.is_active', '=', TRUE])
      ->addSelect('id', 'Booster_QBO.qbo_customer_id', 'member.contact_id_a')
      ->addOrderBy('id', 'ASC')
      ->execute();

    $households = [];
    $assignedStudentIds = [];
    foreach ($rows as $row) {
      $hhId = (int) $row['id'];
      $studentId = (int) $row['member.contact_id_a'];
      if (!isset($studentsById[$studentId])) {
        // Defensive: a join row for a student id we didn't ask about.
        // Should not happen (the join is scoped to $studentIds), but never
        // silently include a student this method wasn't told about.
        continue;
      }
      if (isset($assignedStudentIds[$studentId])) {
        // R3: already assigned to an earlier (lower id, since rows are
        // ordered by household id ascending) household — skip.
        continue;
      }
      if (!isset($households[$hhId])) {
        $households[$hhId] = [
          'id' => $hhId,
          'qbo_customer_id' => $row['Booster_QBO.qbo_customer_id'],
          'students' => [],
        ];
      }
      $households[$hhId]['students'][$studentId] = $studentsById[$studentId];
      $assignedStudentIds[$studentId] = TRUE;
    }

    // A household that ends up with no assigned students (every one of its
    // members here was already claimed by an earlier household, R3) isn't
    // a billing source for this call — drop the empty shell.
    $households = array_filter($households, fn(array $hh): bool => !empty($hh['students']));

    ksort($households);
    foreach ($households as &$hh) {
      ksort($hh['students']);
      $hh['students'] = array_values($hh['students']);
    }
    unset($hh);
    return $households;
  }

  /**
   * The household's COMPLETE active student count — every Individual with a
   * Booster_QBO_Student.qbo_subcustomer_id who is an active "Household
   * Member of" that household — NOT scoped to what any particular parent
   * may see. This is the other half of the §4.6 complete-set gate (C1/C2):
   * BalanceService only cross-checks a household's BalanceWithJobs when a
   * caller's verified student count for that household equals this number.
   *
   * checkPermissions FALSE, COUNT-ONLY (->execute()->count()): this call
   * never materializes a single contact id, name, or qbo id — only an
   * integer — which is deliberate containment (see InvariantTest's
   * allowlist entry): even if $householdId were ever wrong, the worst this
   * method can leak is a COUNT, never which students, who they are, or
   * anything about family identity.
   *
   * @param int $householdId
   *   A household id already produced by billingHouseholdsOf() for the same
   *   contact — never take this from request input.
   */
  public static function activeStudentCountOf(int $householdId): int {
    return Contact::get(FALSE)
      ->addWhere('contact_type', '=', 'Individual')
      ->addWhere('Booster_QBO_Student.qbo_subcustomer_id', 'IS NOT NULL')
      ->addJoin('Relationship AS member', 'INNER', NULL,
        ['member.contact_id_a', '=', 'id'],
        ['member.contact_id_b', '=', $householdId],
        ['member.relationship_type_id:name', '=', '"Household Member of"'],
        ['member.is_active', '=', TRUE])
      ->selectRowCount()
      ->execute()->count();
  }

}
