<?php
namespace Civi\Boosterportal;

use Civi\Api4\SearchDisplay;

/**
 * §6: the reconciliation report — the permanent drift-detection layer.
 *
 * run() covers checks 1,2,5-18 and is meant to be scheduled right after the
 * nightly mirror refresh (Task 10) — most of these checks read
 * boosterportal_qbo_customer, which is only as fresh as the last mirror run.
 * runHourly() covers checks 3, 4, and 4b — CiviCRM-only (no dependency on
 * the mirror), highest severity, and cheap enough to run every hour.
 *
 * Checks 19-20 are volunteer-season checks (design §6) that depend on
 * machinery Phase 1 doesn't build yet (season/enrollment tracking) — they
 * are deliberately NOT stubbed here. The gap between 18 and the next check
 * arriving in Phase 2 is intentional, not a bug in this file.
 *
 * A recent security review made checks #1 and #2 the SOLE control on
 * QBO-side divergence (see InvariantTest's allowlist comment on
 * FamilyResolver.php: FamilyResolver's "complete household" gate can only
 * ever be complete relative to what CiviCRM itself has wired up — if QBO
 * has a sub-customer CiviCRM has never imported/linked, no ACL-layer gate
 * can see that at all). These two checks are what closes that gap, which is
 * why they get first-class test coverage (ReconTest) beyond "fires on some
 * fixture".
 */
class Recon {

  /** The full nightly check universe — passed to store() so a check that
   *  stops firing still gets its stale row cleared (see store()'s docblock). */
  private const NIGHTLY_CHECK_NUMS = [1, 2, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18];

  /** The full hourly check universe. Check 4b shares check_num 4 with
   *  check 4 (see runHourly()'s #4b comment), so there is no separate 4b
   *  entry here — clearing check_num 4 already covers both. */
  private const HOURLY_CHECK_NUMS = [3, 4];

  /**
   * Nightly: checks 1, 2, 5-18. Reads the mirror (boosterportal_qbo_customer)
   * plus live CiviCRM household/student/relationship data.
   *
   * @return array[] Each row: ['check_num' => int, 'severity' => string, 'title' => string, 'detail' => string].
   */
  public function run(): array {
    $f = [];
    $custom = $this->customTables();

    // -- CRITICAL --------------------------------------------------------
    // #1 Student's QBO ParentRef != their Household's qbo_customer_id.
    // This is the SOLE control (with #2) on QBO-side divergence — see the
    // class docblock.
    //
    // COLLATE utf8mb4_unicode_ci on every mirror-table (boosterportal_qbo_
    // customer) VARCHAR column compared against a CiviCRM custom-field
    // column below: sql/install.sql creates the mirror table with a bare
    // `DEFAULT CHARSET=utf8mb4` (no explicit COLLATE), which resolves to
    // this MySQL server's newer default, utf8mb4_0900_ai_ci — while every
    // CiviCRM-owned table (including generated custom-value tables) is
    // utf8mb4_unicode_ci. Comparing/joining VARCHAR columns across the two
    // without forcing a common collation fails outright ("Illegal mix of
    // collations") rather than silently misbehaving — found empirically
    // running this exact check against the test DB. Collating the
    // mirror-table side (rather than the custom-field side, which would
    // need repeating per check) keeps the fix in one place's worth of
    // pattern across checks 1/5/10/16, the only checks that cross this
    // table boundary.
    $f = array_merge($f, $this->sqlCheck(1, 'CRITICAL',
      "Student's QBO sub-customer sits under the wrong family",
      "SELECT s.entity_id AS student_contact_id, m.qbo_id, m.parent_ref, hh.{$custom['hh_col']} AS household_qbo
       FROM {$custom['student_table']} s
       JOIN boosterportal_qbo_customer m ON m.qbo_id COLLATE utf8mb4_unicode_ci = s.{$custom['student_col']}
       JOIN civicrm_relationship r ON r.contact_id_a = s.entity_id
       JOIN civicrm_relationship_type rt ON rt.id = r.relationship_type_id AND rt.name_a_b = 'Household Member of'
       JOIN {$custom['hh_table']} hh ON hh.entity_id = r.contact_id_b
       WHERE m.parent_ref IS NOT NULL AND m.parent_ref COLLATE utf8mb4_unicode_ci <> hh.{$custom['hh_col']}"));

    // #2 Duplicate QBO ids on two Households / two Individuals.
    $f = array_merge($f, $this->sqlCheck(2, 'CRITICAL', 'Same QBO id linked twice',
      "SELECT {$custom['hh_col']} AS qbo_id, COUNT(*) c FROM {$custom['hh_table']}
       WHERE {$custom['hh_col']} IS NOT NULL GROUP BY 1 HAVING c > 1
       UNION ALL
       SELECT {$custom['student_col']}, COUNT(*) c FROM {$custom['student_table']}
       WHERE {$custom['student_col']} IS NOT NULL GROUP BY 1 HAVING c > 1"));

    // -- ERROR -------------------------------------------------------------
    // #5 Household's qbo_customer_id no longer resolves in QBO.
    $f = array_merge($f, $this->sqlCheck(5, 'ERROR', 'Household QBO customer gone from QBO',
      "SELECT hh.entity_id, hh.{$custom['hh_col']} AS qbo_id FROM {$custom['hh_table']} hh
       LEFT JOIN boosterportal_qbo_customer m ON m.qbo_id COLLATE utf8mb4_unicode_ci = hh.{$custom['hh_col']}
       WHERE hh.{$custom['hh_col']} IS NOT NULL AND m.qbo_id IS NULL"));

    // #6 BalanceWithJobs != sum(sub Balance) + own Balance (mirror-only maths).
    $f = array_merge($f, $this->sqlCheck(6, 'ERROR', 'QBO balance cross-check mismatch',
      "SELECT p.qbo_id, p.balance_with_jobs, p.balance AS own,
              COALESCE(SUM(c.balance), 0) AS student_sum
       FROM boosterportal_qbo_customer p
       LEFT JOIN boosterportal_qbo_customer c ON c.parent_ref = p.qbo_id
       WHERE p.parent_ref IS NULL AND p.balance_with_jobs IS NOT NULL
       GROUP BY p.qbo_id, p.balance_with_jobs, p.balance
       HAVING ABS(p.balance_with_jobs - (student_sum + own)) >= 0.01"));

    // #7 Non-zero balance on a parent customer's own record.
    $f = array_merge($f, $this->sqlCheck(7, 'ERROR', 'Invoice sits on the parent, not a student',
      "SELECT p.qbo_id, p.display_name, p.balance FROM boosterportal_qbo_customer p
       WHERE p.parent_ref IS NULL AND p.balance > 0
         AND EXISTS (SELECT 1 FROM boosterportal_qbo_customer c WHERE c.parent_ref = p.qbo_id)"));

    // #8 Student nobody can see.
    $f = array_merge($f, $this->sqlCheck(8, 'ERROR', 'Student with no permissioned parent',
      "SELECT s.entity_id AS student_contact_id, s.{$custom['student_col']} AS qbo_id
       FROM {$custom['student_table']} s
       WHERE s.{$custom['student_col']} IS NOT NULL AND NOT EXISTS (
         SELECT 1 FROM civicrm_relationship r
         WHERE r.contact_id_b = s.entity_id AND r.is_active = 1 AND r.is_permission_a_b > 0)"));

    // #9 Parent with a portal identity but nothing to see.
    $f = array_merge($f, $this->sqlCheck(9, 'ERROR', 'Portal login sees no students',
      "SELECT u.contact_id, u.uf_name FROM civicrm_uf_match u
       JOIN civicrm_contact c ON c.id = u.contact_id AND c.contact_type = 'Individual'
       WHERE NOT EXISTS (
         SELECT 1 FROM civicrm_relationship r
         WHERE r.contact_id_a = u.contact_id AND r.is_active = 1 AND r.is_permission_a_b > 0)"));

    // #10 Balance on a student with no live CiviCRM record ("no active
    // enrollment" refines to season enrollment in Phase 2; Phase 1 proxy:
    // sub-customer owes money but no live student contact carries its id).
    $f = array_merge($f, $this->sqlCheck(10, 'ERROR', 'Balance on a student not on the roster',
      "SELECT m.qbo_id, m.display_name, m.balance FROM boosterportal_qbo_customer m
       WHERE m.parent_ref IS NOT NULL AND m.balance > 0 AND NOT EXISTS (
         SELECT 1 FROM {$custom['student_table']} s
         JOIN civicrm_contact c ON c.id = s.entity_id AND c.is_deleted = 0 AND (c.is_deceased = 0 OR c.is_deceased IS NULL)
         WHERE s.{$custom['student_col']} = m.qbo_id COLLATE utf8mb4_unicode_ci)"));

    // -- WARNING -------------------------------------------------------------
    // #11 Orphan sub-customer (ParentRef missing/inactive/deleted).
    $f = array_merge($f, $this->sqlCheck(11, 'WARNING', 'Orphan QBO sub-customer',
      "SELECT c.qbo_id, c.display_name, c.parent_ref FROM boosterportal_qbo_customer c
       LEFT JOIN boosterportal_qbo_customer p ON p.qbo_id = c.parent_ref
       WHERE c.parent_ref IS NOT NULL AND (p.qbo_id IS NULL OR p.active = 0)"));

    // #12 Nesting deeper than one level (model assumes 2, QBO permits 5).
    $f = array_merge($f, $this->sqlCheck(12, 'WARNING', 'Sub-customer nested below level 2',
      "SELECT c.qbo_id, c.display_name FROM boosterportal_qbo_customer c
       JOIN boosterportal_qbo_customer p ON p.qbo_id = c.parent_ref
       WHERE p.parent_ref IS NOT NULL"));

    // #13 Duplicate parent customers by name or email.
    $f = array_merge($f, $this->sqlCheck(13, 'WARNING', 'Possible duplicate QBO parent customers',
      "SELECT display_name AS dupe_key, COUNT(*) c FROM boosterportal_qbo_customer
       WHERE parent_ref IS NULL GROUP BY display_name HAVING c > 1
       UNION ALL
       SELECT email, COUNT(*) c FROM boosterportal_qbo_customer
       WHERE parent_ref IS NULL AND email IS NOT NULL GROUP BY email HAVING c > 1"));

    // #14 Same student name under two parents.
    $f = array_merge($f, $this->sqlCheck(14, 'WARNING', 'Same student name under two families',
      "SELECT display_name, COUNT(DISTINCT parent_ref) c FROM boosterportal_qbo_customer
       WHERE parent_ref IS NOT NULL GROUP BY display_name HAVING c > 1"));

    // #15 Inactive parent still carrying active sub-customers.
    $f = array_merge($f, $this->sqlCheck(15, 'WARNING', 'Inactive QBO parent with active sub-customers',
      "SELECT p.qbo_id, p.display_name FROM boosterportal_qbo_customer p
       WHERE p.parent_ref IS NULL AND p.active = 0 AND EXISTS (
         SELECT 1 FROM boosterportal_qbo_customer c WHERE c.parent_ref = p.qbo_id AND c.active = 1)"));

    // #16 QBO family with no CiviCRM Household (cannot log in).
    $f = array_merge($f, $this->sqlCheck(16, 'WARNING', 'QBO family missing from CiviCRM',
      "SELECT p.qbo_id, p.display_name FROM boosterportal_qbo_customer p
       WHERE p.parent_ref IS NULL AND p.active = 1
         AND EXISTS (SELECT 1 FROM boosterportal_qbo_customer c WHERE c.parent_ref = p.qbo_id)
         AND NOT EXISTS (SELECT 1 FROM {$custom['hh_table']} hh WHERE hh.{$custom['hh_col']} = p.qbo_id COLLATE utf8mb4_unicode_ci)"));

    // #17 CiviCRM Household with nothing to bill.
    $f = array_merge($f, $this->sqlCheck(17, 'WARNING', 'Household with no QBO customer id',
      "SELECT c.id, c.display_name FROM civicrm_contact c
       LEFT JOIN {$custom['hh_table']} hh ON hh.entity_id = c.id
       WHERE c.contact_type = 'Household' AND c.is_deleted = 0
         AND (hh.entity_id IS NULL OR hh.{$custom['hh_col']} IS NULL)"));

    // #18 Cross-household permissioned edge. DELIBERATELY A WARNING (§6):
    // this is the split-family design working — and also what a
    // misconfiguration looks like. A human reviews this list each season.
    // Do not "optimize" it away as noise.
    $f = array_merge($f, $this->sqlCheck(18, 'WARNING', 'Parent permissioned to a student in another household',
      "SELECT r.contact_id_a AS parent_id, r.contact_id_b AS student_id
       FROM civicrm_relationship r
       WHERE r.is_active = 1 AND r.is_permission_a_b > 0
         AND NOT EXISTS (
           SELECT 1 FROM civicrm_relationship ha
           JOIN civicrm_relationship hb ON hb.contact_id_b = ha.contact_id_b
           JOIN civicrm_relationship_type rt ON rt.id = ha.relationship_type_id AND rt.name_a_b = 'Household Member of'
           JOIN civicrm_relationship_type rt2 ON rt2.id = hb.relationship_type_id AND rt2.name_a_b = 'Household Member of'
           WHERE ha.contact_id_a = r.contact_id_a AND hb.contact_id_a = r.contact_id_b)"));

    $this->store($f, self::NIGHTLY_CHECK_NUMS);
    $this->emailCritical($f);
    return $f;
  }

  /**
   * Hourly: checks 3, 4, and 4b — CiviCRM-only, no dependency on the
   * mirror, highest severity (§6). Cheap enough to run every hour, unlike
   * the nightly checks above which assume a freshly-refreshed mirror.
   */
  public function runHourly(): array {
    $f = [];

    // #3 acl_bypass anywhere — must ALWAYS return zero rows (§3.3). Also
    // the automated build gate (InvariantTest::testNoAclBypassAnywhere) —
    // this is the same check shipped a second time as a persisted,
    // emailable finding rather than a CI-only assertion.
    foreach (SearchDisplay::get(FALSE)->addWhere('acl_bypass', '=', TRUE)
      ->addSelect('name')->execute() as $d) {
      $f[] = ['check_num' => 3, 'severity' => 'CRITICAL',
        'title' => 'SearchKit display has acl_bypass enabled',
        'detail' => json_encode($d)];
    }

    // #4 Student permissioned to > 4 contacts — ACL canary, counted directly
    // off civicrm_relationship so a corrupt ACL cache cannot hide it (§6).
    $dao = \CRM_Core_DAO::executeQuery(
      "SELECT r.contact_id_b AS student_id, COUNT(*) c FROM civicrm_relationship r
       JOIN civicrm_contact ct ON ct.id = r.contact_id_b AND ct.contact_type = 'Individual'
       WHERE r.is_active = 1 AND r.is_permission_a_b > 0
       GROUP BY r.contact_id_b HAVING c > 4");
    while ($dao->fetch()) {
      $f[] = ['check_num' => 4, 'severity' => 'CRITICAL',
        'title' => 'Student permissioned to more than 4 contacts',
        'detail' => json_encode(['student_id' => $dao->student_id, 'count' => $dao->c])];
    }

    // #4b Out-of-band permissioned relationship rows (Task 7 carry-forward
    // amendment): the SAME audit as
    // InvariantTest::testNoOutOfBandPermissionedRows() — a row that bypassed
    // the write-time guard in boosterportal.php (hook_civicrm_pre) — direct
    // SQL, an import, a DB restore — is invisible to that guard forever
    // afterward, and is exposed to CiviCRM core's single-record permission
    // check (CRM_Contact_BAO_Contact_Permission::allow()) even though
    // nothing here ever validated it. This is the ongoing, emailable
    // detector for that gap, grouped under check_num 4 with the ACL canary
    // above (§6 amendment names it "4b" — the findings table's check_num
    // column is a plain INT, so there is no separate "4b" value to store;
    // both checks share check_num 4 and are distinguished by title).
    //
    // Employee type resolved BY ID, not by the string 'Employee of': an
    // admin can rename a RelationshipType's label
    // (civicrm_relationship_type.name_a_b) from the UI, which would
    // silently widen this audit's blind spot if matched on the name
    // string. Wrapped in try/catch: getEmployeeRelationshipTypeID() throws
    // if the 'Employee of' type has been deleted, in which case there is
    // nothing to exempt and $employeeTypeId stays NULL (matches no row).
    $employeeTypeId = NULL;
    try {
      $employeeTypeId = \CRM_Contact_BAO_RelationshipType::getEmployeeRelationshipTypeID();
    }
    catch (\CRM_Core_Exception $e) {
      // 'Employee of' relationship type missing/deleted — nothing to exempt.
    }
    // Portal_Parent_of, by contrast, IS matched by name (not ID) below:
    // it's a managed entity this extension owns (`update = unmodified` in
    // managed/QboFields.mgd.php), so CiviCRM itself will not let it be
    // renamed out from under this check the way a stock/admin-owned type
    // like Employee of can be.
    $oobDao = \CRM_Core_DAO::executeQuery(
      "SELECT r.id FROM civicrm_relationship r
       JOIN civicrm_relationship_type rt ON rt.id = r.relationship_type_id
       WHERE ((r.is_permission_a_b <> 0 OR r.is_permission_b_a <> 0)
              AND rt.name_a_b != 'Portal_Parent_of'
              AND r.relationship_type_id != %1)
          OR (rt.name_a_b = 'Portal_Parent_of' AND r.is_permission_b_a <> 0)",
      [1 => [$employeeTypeId ?? 0, 'Integer']]);
    while ($oobDao->fetch()) {
      $f[] = ['check_num' => 4, 'severity' => 'CRITICAL',
        'title' => 'Permissioned relationship outside the portal model',
        'detail' => json_encode(['relationship_id' => (int) $oobDao->id])];
    }

    $this->store($f, self::HOURLY_CHECK_NUMS);
    $this->emailCritical($f);
    return $f;
  }

  private function sqlCheck(int $num, string $severity, string $title, string $sql): array {
    $out = [];
    $dao = \CRM_Core_DAO::executeQuery($sql);
    while ($dao->fetch()) {
      $out[] = ['check_num' => $num, 'severity' => $severity, 'title' => $title,
        'detail' => json_encode($dao->toArray())];
    }
    return $out;
  }

  /** Resolve the generated custom-field table/column names once, at runtime. */
  private function customTables(): array {
    [$hhTable, $hhCol] = \CRM_Core_BAO_CustomField::getTableColumnGroup(
      \CRM_Core_BAO_CustomField::getCustomFieldID('qbo_customer_id', 'Booster_QBO'));
    [$stTable, $stCol] = \CRM_Core_BAO_CustomField::getTableColumnGroup(
      \CRM_Core_BAO_CustomField::getCustomFieldID('qbo_subcustomer_id', 'Booster_QBO_Student'));
    return ['hh_table' => $hhTable, 'hh_col' => $hhCol,
      'student_table' => $stTable, 'student_col' => $stCol];
  }

  /**
   * Findings are recomputed each run, not appended to forever: clear every
   * check_num this run is RESPONSIBLE for (the full $checkNums universe,
   * e.g. all of run()'s 1,2,5-18 — NOT just the ones that happened to
   * produce a finding this time), then rewrite with whatever $findings
   * holds now.
   *
   * $checkNums must be the full universe, not array_column($findings,
   * 'check_num'): a check that fired last run and produces nothing this
   * run would never appear in $findings at all, so deriving the DELETE
   * scope FROM $findings can never clear that now-stale row — it only ever
   * shrinks the "in" list, never grows it back to cover a check that just
   * went quiet. Caught by
   * ReconTest::testRerunReplacesStalePersistedFindingsForTheSameCheck.
   */
  private function store(array $findings, array $checkNums): void {
    \CRM_Core_DAO::executeQuery('DELETE FROM boosterportal_recon_finding WHERE check_num IN ('
      . implode(',', array_map('intval', $checkNums)) . ')');
    foreach ($findings as $row) {
      \CRM_Core_DAO::executeQuery(
        'INSERT INTO boosterportal_recon_finding (check_num, severity, title, detail, found_at)
         VALUES (%1, %2, %3, %4, NOW())',
        [1 => [$row['check_num'], 'Integer'], 2 => [$row['severity'], 'String'],
         3 => [$row['title'], 'String'], 4 => [$row['detail'], 'String']]);
    }
  }

  private function emailCritical(array $findings): void {
    $critical = array_filter($findings, fn($r) => $r['severity'] === 'CRITICAL');
    $to = \Civi::settings()->get('boosterportal_webmaster_email');
    if (!$critical || !$to) {
      return;
    }
    $body = "CRITICAL reconciliation findings (§6):\n\n";
    foreach ($critical as $c) {
      $body .= "#{$c['check_num']} {$c['title']}\n  {$c['detail']}\n";
    }
    // CRM_Utils_Mail::send() declares its first parameter as array &$params
    // (by reference — it's mutated in place by hook_civicrm_alterMailParams
    // before sending) — an inline array literal isn't an assignable
    // reference and fails outright ("could not be passed by reference"),
    // caught empirically running this against Mailpit (Task 13 report).
    // Building the array into a variable first, then passing the variable,
    // fixes it.
    $mailParams = [
      'from' => $to, 'toEmail' => $to,
      'subject' => '[Booster Portal] CRITICAL reconciliation findings',
      'text' => $body,
    ];
    \CRM_Utils_Mail::send($mailParams);
  }

}
