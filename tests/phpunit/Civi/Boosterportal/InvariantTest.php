<?php
namespace Civi\Boosterportal;

use Civi\Test;
use Civi\Test\HeadlessInterface;
use PHPUnit\Framework\TestCase;

/**
 * §5.3: automated gate for the project's three security invariants, plus two
 * carry-forwards from the Task 7 ACL security review. This is the
 * build-failing gate (§9) — wired into CI (Task 8) on every push.
 */
class InvariantTest extends TestCase implements HeadlessInterface {

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return Test::headless()->installMe(__DIR__)->apply();
  }

  /**
   * Invariant 1 (design §3.3): no SearchKit display anywhere has acl_bypass.
   * Also shipped as hourly reconciliation check #3 (Task 13).
   */
  public function testNoAclBypassAnywhere(): void {
    $count = \Civi\Api4\SearchDisplay::get(FALSE)
      ->addWhere('acl_bypass', '=', TRUE)
      ->execute()->count();
    $this->assertSame(0, $count,
      'A SearchDisplay has acl_bypass enabled. This is the failure that ends the project (design §3.3). Remove it.');
  }

  /**
   * Invariant 2: no parent-reachable code path passes checkPermissions FALSE.
   * Static scan of the extension's Civi/ directory: FALSE is permitted only
   * in files named in the allowlist below, each of which must be genuinely
   * unreachable by a parent (admin pages, cron, importer, tests).
   *
   * Only files that exist today are allowlisted. Future files that will
   * legitimately need FALSE (e.g. a Task-11 Importer.php, a Task-13 cron
   * Recon.php/Mirror.php, a pre-auth MagicLink.php) get added to this list
   * IN the task that introduces them, each with its own justifying comment —
   * pre-allowlisting a file that doesn't exist yet would let it land later
   * with an unreviewed FALSE and this test would never catch it.
   */
  public function testNoUncheckedPermissionsOnParentPaths(): void {
    $allowlist = [
      // Shared fixture builder: used only by PHPUnit test setup (AclLeakTest,
      // RelationshipPermissionGuardTest, AclCacheInvalidationTest,
      // FamilyBuilderTest) and, from Task 11 on, the one-time console
      // importer. Never invoked from a parent-facing request — there is no
      // route or API action that reaches FamilyBuilder::create().
      'Civi/Boosterportal/FamilyBuilder.php',
      // Task 11: the one-time initial-load importer. Reachable only via
      // BoosterPortal.importFamilies, which is gated by 'administer CiviCRM'
      // at the API layer (BoosterPortal::permissions()) — console/admin-only,
      // run once at cutover (Task 18). No parent-facing route or API action
      // reaches Importer::run() directly.
      'Civi/Boosterportal/Importer.php',
      // Task 12: THE FIRST PARENT-REACHABLE ALLOWLIST ENTRY — every prior
      // entry above is test/console-only and never sits behind a
      // parent-permissioned route. This one does: FamilyResolver is called
      // directly from Civi/Api4/Action/BoosterPortal/GetMyBalance.php, whose
      // permission is 'access CiviCRM' (BoosterPortal::permissions()) — i.e.
      // any logged-in parent can trigger this code path. The containment
      // argument this allowlist entry rests on:
      //
      //  1. The ONE checkPermissions FALSE call in this file is
      //     FamilyResolver::billingHouseholdOf() — a Contact::get(FALSE)
      //     reading Households. This is intentional and matches the Task 7
      //     amendment: parents cannot see Household rows at all under this
      //     extension's ACL model (boosterportal.php's aclGroup hook grants
      //     only the Booster_QBO_Student custom group; the aclWhereClause
      //     hook only ever grants contact_id_b of a Portal_Parent_of edge,
      //     which is never a Household) — so there is no ACL-checked way to
      //     do this lookup at all, by design, not by oversight.
      //  2. billingHouseholdOf()'s ONLY input is $studentIds, an int[]. It
      //     is NEVER called with anything from request/API input — the sole
      //     caller (GetMyBalance::_run()) always sources $studentIds from
      //     FamilyResolver::studentsOf()'s OWN return value for the SAME
      //     request, and studentsOf() itself IS ACL-checked (see below), so
      //     by construction the household lookup can only ever be scoped to
      //     ids the parent was already independently verified to see.
      //  3. studentsOf() is the OTHER method in this file, and it does NOT
      //     appear in the offender list above — it contains no
      //     checkPermissions=FALSE at all. It derives CANDIDATE student ids
      //     via plain SQL (mirroring boosterportal_civicrm_aclWhereClause()'s
      //     own subquery), then verifies every one of them with a REAL
      //     Contact::get(TRUE) call before returning anything — see the
      //     "why not the plan's literal join" comment on the class itself
      //     for why that two-layer shape exists (Contact::get(TRUE) joined
      //     directly to Relationship returns zero rows for a portal parent,
      //     empirically verified against the AclLeakTest fixture: the join
      //     drags in Relationship's OWN default ACL clause, which requires
      //     the parent's own contact row to be independently visible, and
      //     it isn't). studentsOf() also throws if called for any contact
      //     other than the currently logged-in one, closing off the one
      //     remaining way this could be aimed at someone else's id.
      //  4. Guarded by AclLeakTest::testGetMyBalanceSeesOnlyOwnStudents()
      //     and ::testGetMyBalanceHouseholdLookupScopedToOwnStudents(),
      //     which run both methods against a real two-family fixture and
      //     assert family A's derivation never contains anything of family
      //     B — including through the FALSE call this entry allowlists.
      'Civi/Boosterportal/FamilyResolver.php',
    ];
    // __DIR__ is .../boosterportal/tests/phpunit/Civi/Boosterportal — 4 levels
    // up is the extension root, whose Civi/ subdirectory is what we scan.
    $root = dirname(__DIR__, 4);
    $offenders = [];
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/Civi'));
    foreach ($it as $file) {
      if ($file->getExtension() !== 'php') {
        continue;
      }
      $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
      if (in_array($rel, $allowlist, TRUE)) {
        continue;
      }
      $src = file_get_contents($file->getPathname());
      if (preg_match('/checkPermissions[\'"\s\]]*\s*(=>|=)\s*FALSE/i', $src)
        // Static (::get/::create/etc(FALSE)) form. Case-insensitive: PHP itself
        // doesn't care whether FALSE/false/False is used, and neither should this.
        || preg_match('/::(get|create|update|delete|save|run)\s*\(\s*FALSE\s*\)/i', $src)
        // Fluent (->setCheckPermissions(FALSE)) form — the idiomatic way to build
        // an Api4 action object incrementally; missed by both patterns above.
        || preg_match('/->\s*setCheckPermissions\s*\(\s*FALSE\s*\)/i', $src)) {
        $offenders[] = $rel;
      }
    }
    $this->assertSame([], $offenders,
      'checkPermissions=FALSE outside the allowlist: [' . implode(', ', $offenders) . ']. '
      . 'If a file is genuinely parent-unreachable, add it to $allowlist above WITH a comment saying why.');
  }

  /**
   * Task 7 carry-forward: the extension's ACL hooks (boosterportal.php,
   * hook_civicrm_aclWhereClause) implement only FIRST-degree relationship
   * permissioning for the bulk/query path — see the long comment on
   * boosterportal_civicrm_aclWhereClause() there, which documents that core
   * itself only wires first-degree relationship permissioning into the
   * single-record path (CRM_Contact_BAO_Contact_Permission::allow()/
   * allowList()) and never into the ACL contact cache that backs bulk
   * queries, which is why the hook exists at all.
   *
   * Core's own 'secondDegRelPermissions' setting, if turned on, would have
   * contacts inherit a related contact's permission to edit OTHER related
   * contacts (chained relationships) — a mechanism this extension's hook
   * never modeled, tested, or scoped to Portal_Parent_of. Enabling it would
   * silently create exactly the bulk-query-vs-single-record divergence this
   * whole invariant suite exists to prevent, on a code path outside the
   * extension entirely. Setting name verified empirically on this install via
   * `cv api4 Setting.getFields` (filtered on "secondDeg"): 'secondDegRelPermissions',
   * default 0, added in CiviCRM 4.3.
   */
  public function testSecondDegRelPermissionsStaysOff(): void {
    $value = \Civi::settings()->get('secondDegRelPermissions');
    $this->assertFalse((bool) $value,
      "secondDegRelPermissions is enabled (value: " . var_export($value, TRUE) . "). "
      . 'The extension\'s ACL hooks only implement first-degree relationship permissioning (Task 7); '
      . 'turning on core\'s second-degree setting would create a bulk-query-vs-single-record permission '
      . 'divergence this extension has never modeled. Disable it (Administer > System Settings) unless '
      . 'the hooks have been extended and re-verified for the 2-hop case first.');
  }

  /**
   * Task 7 carry-forward: the write-time guard in boosterportal.php
   * (hook_civicrm_pre) rejects setting is_permission_a_b/b_a on any
   * relationship type other than Portal_Parent_of (plus core's own
   * Employee-of on-behalf-of-organisation edge — see the N1 comment on that
   * hook, and RelationshipPermissionGuardTest which exercises it directly).
   * That guard only protects the APIv4/BAO write path. Anything that writes
   * civicrm_relationship out of band — raw SQL, a future bulk import, a DB
   * restore from before the guard existed — bypasses it entirely and leaves
   * a row that:
   *   - core's SINGLE-RECORD permission check
   *     (CRM_Contact_BAO_Contact_Permission::allow()) still honours, even
   *     though nothing here ever validated it, and
   *   - the extension's own aclWhereClause hook does NOT grant bulk-query
   *     access for (it is scoped to Portal_Parent_of, A->B direction only),
   *     so an out-of-band row on Portal_Parent_of with the permission bit on
   *     the wrong side (B->A) is invisible in bulk but still exploitable
   *     single-record — the exact split this test suite exists to close.
   *
   * This SQL audit is the backstop for that gap: it must always return zero
   * rows. It also ships as reconciliation check #4b (Task 13).
   *
   * 'Employee of' is core's relationship type for the contribute-on-behalf-
   * of-an-organisation flow, resolved below via the same
   * CRM_Contact_BAO_RelationshipType::getEmployeeRelationshipTypeID() call
   * boosterportal.php's guard uses, and matched by ID rather than by name in
   * the SQL: an admin can rename a RelationshipType's label
   * (civicrm_relationship_type.name_a_b) from the UI, which would silently
   * widen this audit's blind spot if it matched on the string 'Employee of'.
   * Verified empirically on this install via
   * `cv api4 RelationshipType.get '{"where":[["name_a_b","CONTAINS","Employee"]]}'`
   * -> id 5, name_a_b = 'Employee of', name_b_a = 'Employer of' (label kept
   * here only for the human-readable message below).
   *
   * Portal_Parent_of, by contrast, IS matched by name (not ID) below: it's a
   * managed entity this extension owns (`update = unmodified` in its managed
   * XML), so CiviCRM itself will not let it be renamed out from under this
   * test the way a stock/admin-owned type like Employee of can be.
   */
  public function testNoOutOfBandPermissionedRows(): void {
    $employeeTypeId = NULL;
    try {
      $employeeTypeId = \CRM_Contact_BAO_RelationshipType::getEmployeeRelationshipTypeID();
    }
    catch (\CRM_Core_Exception $e) {
      // 'Employee of' relationship type missing/deleted — nothing to exempt;
      // fall through with $employeeTypeId still NULL (matches no row, same
      // as boosterportal.php's own allow-list building in that case).
    }

    $sql = "
      SELECT r.id FROM civicrm_relationship r
      JOIN civicrm_relationship_type rt ON rt.id = r.relationship_type_id
      WHERE ((r.is_permission_a_b <> 0 OR r.is_permission_b_a <> 0)
             AND rt.name_a_b != 'Portal_Parent_of'
             AND r.relationship_type_id != %1)
         OR (rt.name_a_b = 'Portal_Parent_of' AND r.is_permission_b_a <> 0)
    ";
    $offenders = [];
    $dao = \CRM_Core_DAO::executeQuery($sql, [
      1 => [$employeeTypeId ?? 0, 'Integer'],
    ]);
    while ($dao->fetch()) {
      $offenders[] = (int) $dao->id;
    }
    $this->assertSame([], $offenders,
      'Out-of-band permissioned relationship row(s) found (civicrm_relationship.id: ['
      . implode(', ', $offenders) . ']) — Task 7 carry-forward audit. These rows bypassed the '
      . 'write-time guard in boosterportal.php (hook_civicrm_pre) and are exposed to core\'s '
      . 'single-record permission check even though the extension\'s own ACL hook never accounted '
      . 'for them. Investigate how they were written (raw SQL? import? restore?) and either delete '
      . 'the permission bit or route the write through Api4 Relationship::create() so the guard sees it.');
  }

}
