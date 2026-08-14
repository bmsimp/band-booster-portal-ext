<?php
namespace Civi\Boosterportal;

use Civi\Api4\Contact;

/**
 * Derives the LOGGED-IN parent's own family — their students, then the
 * billing household those students belong to — for GetMyBalance (Task 12,
 * §4.6). No method here ever accepts a caller-supplied contact/household id
 * from outside this extension's own already-ACL-verified output: studentsOf()
 * takes a contact id but immediately asserts it IS the session contact, and
 * billingHouseholdOf() only ever receives ids that studentsOf() itself
 * already verified. This is what keeps GetMyBalance's "no request parameter
 * ever selects whose family to look up" guarantee true one level down.
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
 * billingHouseholdOf() is the one sanctioned exception to invariant 2
 * (checkPermissions FALSE) in this extension's parent-reachable code —
 * documented in full on that method and in InvariantTest's allowlist.
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
   * The billing Household (§4.4 — the QBO billing anchor; no ACL role of
   * its own) that the given, already ACL-verified student ids belong to.
   *
   * checkPermissions FALSE (Task 7 amendment, restated): parents cannot see
   * Household rows at all — the aclGroup grant in boosterportal.php covers
   * only the Booster_QBO_Student custom group, and the aclWhereClause hook
   * only ever grants contact_id_b of a Portal_Parent_of edge, which is
   * never a Household. This is the sanctioned, documented exception to
   * invariant 2 for this file (see InvariantTest's allowlist entry on
   * FamilyResolver.php for the containment argument). Its safety rests
   * entirely on $studentIds having ALREADY been through studentsOf()'s
   * ACL-checked verification before reaching here — this method itself
   * performs no permission check and MUST NOT be called with ids sourced
   * from request input.
   *
   * @param int[] $studentIds
   *   ACL-verified student contact ids (i.e. the 'id' values returned by
   *   studentsOf() for the same contact) — never take this from request
   *   input.
   * @return array{id: int, qbo_customer_id: string}|null
   *   NULL if $studentIds is empty or no such household exists.
   */
  public static function billingHouseholdOf(array $studentIds): ?array {
    if (!$studentIds) {
      return NULL;
    }

    $household = Contact::get(FALSE)
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
      ->addSelect('id', 'Booster_QBO.qbo_customer_id')
      ->execute()->first();

    if (!$household) {
      return NULL;
    }
    return [
      'id' => (int) $household['id'],
      'qbo_customer_id' => $household['Booster_QBO.qbo_customer_id'],
    ];
  }

}
