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
   * The collation every generated custom-field table/column uses (checks
   * 1/5/10/16 join boosterportal_qbo_customer, a mirror-table VARCHAR
   * column, against one of these — see the COLLATE comment on run() for
   * why that needs forcing explicitly). Named for what it IS, not where
   * it's used, since it was previously the literal string
   * 'utf8mb4_unicode_ci' repeated at every one of those four join sites —
   * a coordinator review (post-79d8c8f) flagged the repetition risk: a
   * typo in any one copy fails that single check silently-different
   * (wrong collation error) rather than obviously.
   */
  private const CUSTOM_FIELD_COLLATION = 'utf8mb4_unicode_ci';

  /**
   * Nightly: checks 1, 2, 5-18. Reads the mirror (boosterportal_qbo_customer)
   * plus live CiviCRM household/student/relationship data.
   *
   * @return array[] Each row: ['check_num' => int, 'severity' => string, 'title' => string, 'detail' => string].
   */
  public function run(): array {
    $f = [];
    $custom = $this->customTables();
    // Class constants can't be interpolated directly inside a double-quoted
    // string (`{self::X}` is a parse error) — a local copy is the
    // established workaround, same reason $custom's keys get pulled into
    // the SQL via `{$custom['hh_col']}` rather than referencing the array
    // inline.
    $collate = self::CUSTOM_FIELD_COLLATION;

    // -- CRITICAL --------------------------------------------------------
    // #1 Student's QBO ParentRef != their Household's qbo_customer_id.
    // This is the SOLE control (with #2) on QBO-side divergence — see the
    // class docblock.
    //
    // COLLATE utf8mb4_unicode_ci (self::CUSTOM_FIELD_COLLATION below) on
    // every mirror-table (boosterportal_qbo_customer) VARCHAR column compared against a
    // CiviCRM custom-field column below: sql/install.sql creates the
    // mirror table with a bare `DEFAULT CHARSET=utf8mb4` (no explicit
    // COLLATE), which resolves to this MySQL server's newer default,
    // utf8mb4_0900_ai_ci — while every CiviCRM-owned table (including
    // generated custom-value tables) is utf8mb4_unicode_ci. Comparing/
    // joining VARCHAR columns across the two without forcing a common
    // collation fails outright ("Illegal mix of collations") rather than
    // silently misbehaving — found empirically running this exact check
    // against the test DB. Collating the mirror-table side (rather than
    // the custom-field side, which would need repeating per check) keeps
    // the fix in one place's worth of pattern across checks 1/5/10/16, the
    // only checks that cross this table boundary. Trade-off, noted per a
    // coordinator review: forcing COLLATE on a column defeats MySQL's
    // ability to seek that column's own index (the optimizer can't use a
    // btree built in the column's native collation once the comparison
    // requires a different one), so every one of these four joins full-
    // scans the mirror-table side. Fine at this scale — the mirror table
    // is bounded by the district's actual family/student count, low
    // hundreds of rows at most — but would need revisiting (e.g. a
    // generated column stored in the target collation, indexed) if this
    // table ever grows enough for that scan to matter under load.
    //
    // WHERE clause: `m.parent_ref IS NULL OR ... <> hh.col`, not
    // `IS NOT NULL AND ... <> hh.col` (probe-verified gap, coordinator
    // review of 79d8c8f). A demoted/never-parented QBO sub-customer — one
    // CiviCRM has a live student contact linked to (via
    // qbo_subcustomer_id) and that carries a real balance in QBO, but
    // whose ParentRef QBO itself has gone NULL on — used to produce ZERO
    // findings from this check (or anywhere else — see the check 10/11
    // review below): the old AND required a non-NULL parent_ref before
    // testing whether it pointed at the right household, so a NULL
    // parent_ref short-circuited past the divergence test entirely. A
    // linked student whose mirror row LACKS the expected parent IS the
    // divergence — NULL is not "no opinion", it's "wrong". Regression:
    // ReconTest::testCheck1FiresWhenLinkedStudentsMirrorRowHasNullParentRef().
    //
    // civicrm_contact joins (sc/hc) with is_deleted = 0 on both legs
    // (student and household) — a coordinator review paired this with the
    // check 2 fix below: a trashed student or household contact is gone
    // from CiviCRM's perspective and must not be treated as live evidence
    // of a divergence (or its absence).
    $f = array_merge($f, $this->sqlCheck(1, 'CRITICAL',
      "Student's QBO sub-customer sits under the wrong family",
      "SELECT s.entity_id AS student_contact_id, m.qbo_id, m.parent_ref, hh.{$custom['hh_col']} AS household_qbo
       FROM {$custom['student_table']} s
       JOIN civicrm_contact sc ON sc.id = s.entity_id AND sc.is_deleted = 0
       JOIN boosterportal_qbo_customer m ON m.qbo_id COLLATE {$collate} = s.{$custom['student_col']}
       JOIN civicrm_relationship r ON r.contact_id_a = s.entity_id
       JOIN civicrm_relationship_type rt ON rt.id = r.relationship_type_id AND rt.name_a_b = 'Household Member of'
       JOIN {$custom['hh_table']} hh ON hh.entity_id = r.contact_id_b
       JOIN civicrm_contact hc ON hc.id = hh.entity_id AND hc.is_deleted = 0
       WHERE (m.parent_ref IS NULL OR m.parent_ref COLLATE {$collate} <> hh.{$custom['hh_col']})"));

    // #2 Duplicate QBO ids on two Households / two Individuals.
    //
    // civicrm_contact joins (hc/sc) with is_deleted = 0 on both legs
    // (probe-verified gap, coordinator review of 79d8c8f): without them, a
    // trashed duplicate (e.g. the losing side of a since-resolved merge,
    // or a household deleted after a data-entry mistake) still counted
    // toward `HAVING c > 1` — a legitimately-resolved duplicate would keep
    // paging as CRITICAL forever, training whoever reads the report to
    // ignore check 2. Regression:
    // ReconTest::testCheck2StopsFiringOnceOneDuplicateHouseholdIsTrashed().
    $f = array_merge($f, $this->sqlCheck(2, 'CRITICAL', 'Same QBO id linked twice',
      "SELECT hh.{$custom['hh_col']} AS qbo_id, COUNT(*) c
       FROM {$custom['hh_table']} hh
       JOIN civicrm_contact hc ON hc.id = hh.entity_id AND hc.is_deleted = 0
       WHERE hh.{$custom['hh_col']} IS NOT NULL GROUP BY 1 HAVING c > 1
       UNION ALL
       SELECT s.{$custom['student_col']} AS qbo_id, COUNT(*) c
       FROM {$custom['student_table']} s
       JOIN civicrm_contact sc ON sc.id = s.entity_id AND sc.is_deleted = 0
       WHERE s.{$custom['student_col']} IS NOT NULL GROUP BY 1 HAVING c > 1"));

    // -- ERROR -------------------------------------------------------------
    // #5 Household's qbo_customer_id no longer resolves in QBO.
    $f = array_merge($f, $this->sqlCheck(5, 'ERROR', 'Household QBO customer gone from QBO',
      "SELECT hh.entity_id, hh.{$custom['hh_col']} AS qbo_id FROM {$custom['hh_table']} hh
       LEFT JOIN boosterportal_qbo_customer m ON m.qbo_id COLLATE {$collate} = hh.{$custom['hh_col']}
       WHERE hh.{$custom['hh_col']} IS NOT NULL AND m.qbo_id IS NULL"));

    // #6 BalanceWithJobs != sum(sub Balance) + own Balance (mirror-only
    // maths). `p.parent_ref IS NULL` here is population selection (which
    // mirror rows ARE parent/household-level records to run this cross-
    // check over), not link validation like check 1's use of parent_ref —
    // it doesn't need the check 1/check 2 OR-NULL treatment (coordinator
    // review of 79d8c8f flagged this line alongside checks 10/11 as also
    // guarding IS NOT NULL; reviewed and left as-is, noted here rather
    // than silently).
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
    //
    // WARNING rather than ERROR: this is "somebody's family is set up wrong and
    // they will sign in to an empty page", which wants looking at this week. It
    // is not the class of thing checks 3 and 4 report.
    //
    // Staff accounts are excluded (see isPortalLoginAccount()). A board member
    // or volunteer signs in through SSO with an account of their own that has
    // no Portal_Parent_of relationship and never will, so the raw query is
    // permanently true of them.
    $f = array_merge($f, $this->portalLoginsWithNoStudents());

    // #10 Balance on a student with no live CiviCRM record ("no active
    // enrollment" refines to season enrollment in Phase 2; Phase 1 proxy:
    // sub-customer owes money but no live student contact carries its id).
    //
    // `m.parent_ref IS NOT NULL` reviewed for the check 1/2 OR-NULL
    // treatment (coordinator review of 79d8c8f) and deliberately LEFT
    // AS-IS: here it's a population filter distinguishing "this mirror row
    // is sub-customer-shaped" from "this mirror row is parent-shaped", not
    // a test of whether a KNOWN link's parent_ref value is correct (that's
    // check 1's job, and check 1 now fires on exactly this row whenever a
    // live CiviCRM student is actually linked to it — see the NULL-
    // parent_ref regression on check 1 above). Widening this filter to
    // `IS NULL OR IS NOT NULL` (i.e. dropping it) would examine every
    // parent-level mirror row too, and since parent-level qbo_ids never
    // appear in the student custom-field table by construction, the
    // NOT EXISTS below would be true for every one of them — every
    // ordinary parent's own balance would falsely trip "student not on
    // the roster", duplicating check 7's job with false-positive noise.
    $f = array_merge($f, $this->sqlCheck(10, 'ERROR', 'Balance on a student not on the roster',
      "SELECT m.qbo_id, m.display_name, m.balance FROM boosterportal_qbo_customer m
       WHERE m.parent_ref IS NOT NULL AND m.balance > 0 AND NOT EXISTS (
         SELECT 1 FROM {$custom['student_table']} s
         JOIN civicrm_contact c ON c.id = s.entity_id AND c.is_deleted = 0 AND (c.is_deceased = 0 OR c.is_deceased IS NULL)
         WHERE s.{$custom['student_col']} = m.qbo_id COLLATE {$collate})"));

    // -- WARNING -------------------------------------------------------------
    // #11 Orphan sub-customer (ParentRef missing/inactive/deleted).
    //
    // `c.parent_ref IS NOT NULL` reviewed for the check 1/2 OR-NULL
    // treatment (coordinator review of 79d8c8f) and deliberately LEFT
    // AS-IS: this check's whole question — "does this row's DECLARED
    // parent resolve?" — only has meaning for a row that declares a
    // parent in the first place; a row with parent_ref NULL isn't
    // claiming a parent at all, so there is nothing here for it to be
    // orphaned FROM. Widening this to cover NULL would require pulling in
    // CiviCRM linkage data (is there a live student contact pointing at
    // this row?) to distinguish "legitimately top-level" from "demoted
    // sub-customer" — exactly what check 1 already does, at CRITICAL
    // rather than this check's WARNING, and check 1 now covers the
    // demoted/NULL case fully (see above). Keeping #11 scoped to
    // QBO-internal-only broken parent pointers (a non-NULL parent_ref
    // that doesn't resolve to any known row) keeps its query mirror-only,
    // same as before, with no overlap-by-construction against check 1.
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
         AND NOT EXISTS (SELECT 1 FROM {$custom['hh_table']} hh WHERE hh.{$custom['hh_col']} = p.qbo_id COLLATE {$collate})"));

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

  /**
   * Check 9's rows: accounts that can sign in to the portal and would see
   * nothing when they did.
   *
   * Written out rather than handed to sqlCheck() because the SQL alone cannot
   * answer the question. "Has a login and no students" is equally true of a
   * broken parent and of the webmaster's own account, and CiviCRM holds
   * nothing that tells them apart -- the difference lives in the CMS, in which
   * roles the account carries.
   */
  private function portalLoginsWithNoStudents(): array {
    $nonPortalRoles = $this->nonPortalRoles();
    $out = [];
    $dao = \CRM_Core_DAO::executeQuery(
      "SELECT u.uf_id, u.contact_id, u.uf_name FROM civicrm_uf_match u
       JOIN civicrm_contact c ON c.id = u.contact_id AND c.contact_type = 'Individual'
       WHERE NOT EXISTS (
         SELECT 1 FROM civicrm_relationship r
         WHERE r.contact_id_a = u.contact_id AND r.is_active = 1 AND r.is_permission_a_b > 0)");
    while ($dao->fetch()) {
      if (!self::isPortalLoginAccount(self::cmsRolesOf((int) $dao->uf_id), $nonPortalRoles)) {
        continue;
      }
      $out[] = ['check_num' => 9, 'severity' => 'WARNING', 'title' => 'Portal login sees no students',
        'detail' => json_encode($dao->toArray())];
    }
    return $out;
  }

  /**
   * The configured list of CMS roles that mean "not a band parent".
   *
   * Falls back to the setting's own default if the stored value is not a list,
   * so a hand-edited setting cannot accidentally empty the exclusion and bring
   * every staff account back into the report.
   */
  private function nonPortalRoles(): array {
    $configured = \Civi::settings()->get('boosterportal_non_portal_roles');
    return is_array($configured) && $configured !== []
      ? $configured
      : ['administrator', 'editor', 'author', 'contributor', 'booster_volunteer'];
  }

  /**
   * The CMS roles of a CMS account id.
   *
   * The one place in Recon that talks to the CMS, kept deliberately thin -- a
   * lookup, no logic -- for the same reason
   * CRM_Boosterportal_Page_PortalLogin::hasElevatedCapability() is: the
   * headless PHPUnit bootstrap boots CiviCRM's classloader alone, so
   * get_userdata() does not exist in that process at all.
   *
   * Returning [] when there is no CMS is the safe direction for this check. It
   * means "no staff role found", so the account stays IN the report rather than
   * silently dropping out of it, and the headless tests exercise check 9 as
   * they always did.
   */
  private static function cmsRolesOf(int $ufId): array {
    if (!function_exists('get_userdata')) {
      return [];
    }
    $user = get_userdata($ufId);
    return ($user && is_array($user->roles ?? NULL)) ? $user->roles : [];
  }

  /**
   * Is this account one check 9 should have an opinion about?
   *
   * Pure and public so it is testable without a CMS. Excludes rather than
   * includes on purpose: an account holding a listed staff role is skipped, and
   * everything else is examined. The inclusive form ("only accounts holding the
   * parent role") would quietly stop examining a real parent whose account had
   * drifted -- an alarm that goes silent when its assumptions rot is worse than
   * one that goes noisy, because nobody notices silence.
   *
   * @param string[] $roles
   *   The account's CMS roles. Non-string entries are ignored rather than
   *   interpreted; [] means no staff role was found, so the account is
   *   examined.
   * @param string[] $nonPortalRoles
   *   The configured staff roles.
   */
  public static function isPortalLoginAccount(array $roles, array $nonPortalRoles): bool {
    foreach ($roles as $role) {
      if (is_string($role) && in_array($role, $nonPortalRoles, TRUE)) {
        return FALSE;
      }
    }
    return TRUE;
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
   *
   * DELETE and every INSERT run inside one CRM_Core_Transaction::create(TRUE)
   * frame (coordinator review of 79d8c8f) — a real nested SAVEPOINT, same
   * reasoning as FamilyBuilder::create()'s use of TRUE (see its docblock):
   * without this, a concurrent read against boosterportal_recon_finding
   * (the ReconDashboard SearchDisplay, e.g., an admin with the dashboard
   * open while cron runs) landing between the DELETE and the last INSERT
   * would see a false-clean, mid-rewrite table — briefly zero rows for
   * whatever check_num buckets this run just cleared, even though the run
   * isn't actually clean yet. TRUE also means this is always its own
   * atomic unit regardless of what transaction (if any) the caller already
   * has open, same as run()/runHourly()'s callers (the RunRecon/
   * RunReconHourly API actions) never needing to know or care.
   */
  private function store(array $findings, array $checkNums): void {
    \CRM_Core_Transaction::create(TRUE)->run(function () use ($findings, $checkNums) {
      \CRM_Core_DAO::executeQuery('DELETE FROM boosterportal_recon_finding WHERE check_num IN ('
        . implode(',', array_map('intval', $checkNums)) . ')');
      foreach ($findings as $row) {
        \CRM_Core_DAO::executeQuery(
          'INSERT INTO boosterportal_recon_finding (check_num, severity, title, detail, found_at)
           VALUES (%1, %2, %3, %4, NOW())',
          [1 => [$row['check_num'], 'Integer'], 2 => [$row['severity'], 'String'],
           3 => [$row['title'], 'String'], 4 => [$row['detail'], 'String']]);
      }
    });
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
