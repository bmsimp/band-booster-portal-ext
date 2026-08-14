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
        || preg_match('/::(get|create|update|delete|save|run)\s*\(\s*FALSE\s*\)/', $src)) {
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
   * of-an-organisation flow. Verified empirically on this install (not
   * assumed from core's default data) via
   * `cv api4 RelationshipType.get '{"where":[["name_a_b","CONTAINS","Employee"]]}'`
   * -> id 5, name_a_b = 'Employee of', name_b_a = 'Employer of' — the same
   * type CRM_Contact_BAO_RelationshipType::getEmployeeRelationshipTypeID()
   * resolves and the same exemption boosterportal.php's guard carries.
   */
  public function testNoOutOfBandPermissionedRows(): void {
    $sql = "
      SELECT r.id FROM civicrm_relationship r
      JOIN civicrm_relationship_type rt ON rt.id = r.relationship_type_id
      WHERE ((r.is_permission_a_b <> 0 OR r.is_permission_b_a <> 0)
             AND rt.name_a_b NOT IN ('Portal_Parent_of', 'Employee of'))
         OR (rt.name_a_b = 'Portal_Parent_of' AND r.is_permission_b_a <> 0)
    ";
    $offenders = [];
    $dao = \CRM_Core_DAO::executeQuery($sql);
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
