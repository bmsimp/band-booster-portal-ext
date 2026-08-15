<?php
namespace Civi\Boosterportal;

use Civi\Api4\Contact;
use Civi\Api4\RelationshipType;
use Civi\Test;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * §6: the reconciliation report — checks 1,2,5-18 nightly, 3/4/4b hourly.
 * A recent security review made checks #1/#2 the SOLE control on QBO-side
 * divergence (see InvariantTest's allowlist comment on FamilyResolver.php),
 * so they get first-class coverage here, not just "fires on some fixture".
 * Checks 19-20 are volunteer-season checks deferred to Phase 2 — see the
 * comment on Recon::run() for why the numbering has a gap.
 */
class ReconTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return Test::headless()->installMe(__DIR__)->apply();
  }

  /**
   * Belt, on top of TransactionalInterface's per-test rollback — same
   * reasoning and DELETE-not-TRUNCATE approach as MirrorTest::setUp() /
   * ImporterTest::setUp(): CiviTestListener::startTest() opens the wrapping
   * transaction before this setUp() runs, so TRUNCATE's implicit COMMIT
   * would defeat rollback isolation for the rest of the test.
   */
  protected function setUp(): void {
    parent::setUp();
    \CRM_Core_DAO::executeQuery('DELETE FROM boosterportal_qbo_customer');
    \CRM_Core_DAO::executeQuery('DELETE FROM boosterportal_qbo_balance_history');
    \CRM_Core_DAO::executeQuery('DELETE FROM boosterportal_login_token');
    \CRM_Core_DAO::executeQuery('DELETE FROM boosterportal_recon_finding');
  }

  private function mirrorRow(string $id, string $name, ?string $parent, float $bal,
    ?float $bwj = NULL, int $active = 1, ?string $email = NULL): void {
    \CRM_Core_DAO::executeQuery(
      'INSERT INTO boosterportal_qbo_customer
        (qbo_id, display_name, active, parent_ref, balance, balance_with_jobs, email, synced_at)
       VALUES (%1, %2, %3, NULLIF(%4, \'\'), %5, NULLIF(%6, \'x\'), NULLIF(%7, \'\'), NOW())',
      [1 => [$id, 'String'], 2 => [$name, 'String'], 3 => [$active, 'Integer'],
       4 => [$parent ?? '', 'String'], 5 => [$bal, 'Float'],
       6 => [$bwj === NULL ? 'x' : (string) $bwj, 'String'], 7 => [$email ?? '', 'String']]);
  }

  private function createLoggedInUserFor(int $contactId): void {
    $session = \CRM_Core_Session::singleton();
    $session->set('userID', $contactId);
    // uf_id derived from $contactId (not a fixed constant) — same reasoning
    // as AclLeakTest::createLoggedInUserFor(): civicrm_uf_match.uf_id is
    // unique, and more than one test below logs in as more than one contact.
    \Civi\Api4\UFMatch::create(FALSE)
      ->addValue('uf_id', 91000 + $contactId)
      ->addValue('contact_id', $contactId)
      ->addValue('uf_name', "recontest-{$contactId}@example.org")
      ->execute();
  }

  // -------------------------------------------------------------------
  // Clean-data baseline. THE important test in this file (per plan Step
  // 5): any check that fires on a clean fixture is a bug now, or an
  // ignored alarm later.
  // -------------------------------------------------------------------

  public function testCleanDataYieldsNoFindings(): void {
    FamilyBuilder::create([
      'household' => ['name' => 'Clean Family', 'qbo_customer_id' => '101'],
      'parents' => [['first_name' => 'Cee', 'last_name' => 'Clean', 'email' => 'cee@example.org']],
      'students' => [['first_name' => 'Kid', 'last_name' => 'Clean', 'qbo_subcustomer_id' => '102']],
    ]);
    $this->mirrorRow('101', 'Clean Family', NULL, 0.0, 620.0, 1, 'cee@example.org');
    $this->mirrorRow('102', 'Kid Clean', '101', 620.0);
    $findings = (new Recon())->run();
    $this->assertSame([], $findings, 'Clean fixture must produce zero findings; got: ' . json_encode($findings));
  }

  public function testCleanDataYieldsNoHourlyFindings(): void {
    FamilyBuilder::create([
      'household' => ['name' => 'Clean Hourly Family', 'qbo_customer_id' => '201'],
      'parents' => [['first_name' => 'Cee', 'last_name' => 'ClHourly', 'email' => 'ceeh@example.org']],
      'students' => [['first_name' => 'Kid', 'last_name' => 'ClHourly', 'qbo_subcustomer_id' => '202']],
    ]);
    $findings = (new Recon())->runHourly();
    $this->assertSame([], $findings, 'Clean fixture must produce zero HOURLY findings; got: ' . json_encode($findings));
  }

  // -------------------------------------------------------------------
  // Check 1 — FIRST-CLASS: student's QBO ParentRef diverges from their
  // Household's qbo_customer_id. Per the security review, this (plus
  // check 2) is now the SOLE control on QBO-side divergence, since
  // FamilyResolver's "complete household" gate can only ever be complete
  // relative to what CiviCRM itself has wired up.
  // -------------------------------------------------------------------

  public function testCheck1FiresWhenStudentSitsUnderWrongQboParent(): void {
    FamilyBuilder::create([
      'household' => ['name' => 'Wronged Family', 'qbo_customer_id' => '101'],
      'parents' => [['first_name' => 'W', 'last_name' => 'Wronged', 'email' => 'w@example.org']],
      'students' => [['first_name' => 'Kid', 'last_name' => 'Wronged', 'qbo_subcustomer_id' => '102']],
    ]);
    $this->mirrorRow('101', 'Wronged Family', NULL, 0.0, 0.0, 1, 'w@example.org');
    $this->mirrorRow('102', 'Kid Wronged', '999', 0.0); // ParentRef points elsewhere!
    $this->mirrorRow('999', 'Other Family', NULL, 0.0, 0.0);
    $findings = (new Recon())->run();
    $nums = array_column($findings, 'check_num');
    $this->assertContains(1, $nums, 'Check 1 (student under wrong family) must fire — this is the balance-leak precursor');
    $check1 = current(array_filter($findings, fn($f) => $f['check_num'] === 1));
    $this->assertSame('CRITICAL', $check1['severity'], 'Check 1 must be CRITICAL — it is the sole control on QBO-side divergence');
  }

  /**
   * Probe-verified gap (coordinator review of 79d8c8f): a demoted/never-
   * parented QBO sub-customer — CiviCRM has a live student contact linked
   * to it via qbo_subcustomer_id, the mirror row carries a real balance,
   * but QBO's own ParentRef on that row is NULL — used to produce ZERO
   * findings anywhere, because check 1's WHERE required `parent_ref IS NOT
   * NULL` before testing it against the household. A linked student whose
   * mirror row lacks the expected parent IS the divergence.
   */
  public function testCheck1FiresWhenLinkedStudentsMirrorRowHasNullParentRef(): void {
    FamilyBuilder::create([
      'household' => ['name' => 'Demoted Family', 'qbo_customer_id' => '401'],
      'parents' => [['first_name' => 'D', 'last_name' => 'Demoted', 'email' => 'd@example.org']],
      'students' => [['first_name' => 'Kid', 'last_name' => 'Demoted', 'qbo_subcustomer_id' => '402']],
    ]);
    $this->mirrorRow('401', 'Demoted Family', NULL, 0.0, 0.0, 1, 'd@example.org');
    $this->mirrorRow('402', 'Kid Demoted', NULL, 250.0); // parent_ref NULL, real balance owed
    $findings = (new Recon())->run();
    $nums = array_column($findings, 'check_num');
    $this->assertContains(1, $nums,
      "Check 1 must fire when a linked student's mirror row has a NULL ParentRef and a live balance — NULL is not \"no opinion\", it's \"wrong\"");
    $check1 = current(array_filter($findings, fn($f) => $f['check_num'] === 1));
    $this->assertSame('CRITICAL', $check1['severity']);
  }

  public function testCheck1DoesNotFireWhenParentRefMatchesHousehold(): void {
    FamilyBuilder::create([
      'household' => ['name' => 'Right Family', 'qbo_customer_id' => '301'],
      'parents' => [['first_name' => 'R', 'last_name' => 'Right', 'email' => 'r@example.org']],
      'students' => [['first_name' => 'Kid', 'last_name' => 'Right', 'qbo_subcustomer_id' => '302']],
    ]);
    $this->mirrorRow('301', 'Right Family', NULL, 0.0, 0.0, 1, 'r@example.org');
    $this->mirrorRow('302', 'Kid Right', '301', 0.0);
    $nums = array_column((new Recon())->run(), 'check_num');
    $this->assertNotContains(1, $nums, 'Check 1 must not fire when the ParentRef genuinely matches the household');
  }

  // -------------------------------------------------------------------
  // Check 2 — FIRST-CLASS, both variants: the same QBO id linked from two
  // separate CiviCRM records (Household side and Student side).
  // -------------------------------------------------------------------

  public function testCheck2FiresOnDuplicateHouseholdQboId(): void {
    FamilyBuilder::create([
      'household' => ['name' => 'Dupe House A', 'qbo_customer_id' => '501'],
      'parents' => [['first_name' => 'A', 'last_name' => 'DupeA']],
      'students' => [],
    ]);
    FamilyBuilder::create([
      'household' => ['name' => 'Dupe House B', 'qbo_customer_id' => '501'],
      'parents' => [['first_name' => 'B', 'last_name' => 'DupeB']],
      'students' => [],
    ]);
    $nums = array_column((new Recon())->run(), 'check_num');
    $this->assertContains(2, $nums, 'Check 2 must fire when two Households carry the same qbo_customer_id');
  }

  public function testCheck2FiresOnDuplicateStudentQboId(): void {
    FamilyBuilder::create([
      'household' => ['name' => 'Dupe Student House', 'qbo_customer_id' => '601'],
      'parents' => [['first_name' => 'P', 'last_name' => 'DupeStudent']],
      'students' => [
        ['first_name' => 'S1', 'last_name' => 'DupeStudent', 'qbo_subcustomer_id' => '602'],
        ['first_name' => 'S2', 'last_name' => 'DupeStudent', 'qbo_subcustomer_id' => '602'],
      ],
    ]);
    $nums = array_column((new Recon())->run(), 'check_num');
    $this->assertContains(2, $nums, 'Check 2 must fire when two Students carry the same qbo_subcustomer_id');
  }

  /**
   * Probe-verified gap (coordinator review of 79d8c8f): check 2 had no
   * civicrm_contact join, so a trashed (is_deleted=1) duplicate still
   * counted toward `HAVING c > 1` — a legitimately-resolved duplicate
   * (e.g. one side trashed after a merge) would keep paging as CRITICAL
   * forever.
   */
  public function testCheck2StopsFiringOnceOneDuplicateHouseholdIsTrashed(): void {
    $familyA = FamilyBuilder::create([
      'household' => ['name' => 'Trash Dupe House A', 'qbo_customer_id' => '551'],
      'parents' => [['first_name' => 'A', 'last_name' => 'TrashDupeA']],
      'students' => [],
    ]);
    FamilyBuilder::create([
      'household' => ['name' => 'Trash Dupe House B', 'qbo_customer_id' => '551'],
      'parents' => [['first_name' => 'B', 'last_name' => 'TrashDupeB']],
      'students' => [],
    ]);
    $nums = array_column((new Recon())->run(), 'check_num');
    $this->assertContains(2, $nums, 'Sanity: two live Households sharing a qbo_customer_id must fire check 2');

    \CRM_Core_DAO::executeQuery('UPDATE civicrm_contact SET is_deleted = 1 WHERE id = %1',
      [1 => [$familyA['household_id'], 'Integer']]);
    $nums = array_column((new Recon())->run(), 'check_num');
    $this->assertNotContains(2, $nums, 'Trashing one of the two duplicate Households must clear check 2 on re-run');
  }

  public function testCheck2DoesNotFireOnDistinctIds(): void {
    FamilyBuilder::create([
      'household' => ['name' => 'Unique House', 'qbo_customer_id' => '701'],
      'parents' => [['first_name' => 'U', 'last_name' => 'Unique']],
      'students' => [['first_name' => 'Kid', 'last_name' => 'Unique', 'qbo_subcustomer_id' => '702']],
    ]);
    $nums = array_column((new Recon())->run(), 'check_num');
    $this->assertNotContains(2, $nums);
  }

  // -------------------------------------------------------------------
  // Check 6 — balance cross-check mismatch (mirror-only maths).
  // -------------------------------------------------------------------

  public function testCheck6FiresOnBalanceMismatch(): void {
    $this->mirrorRow('101', 'Mismatch Family', NULL, 0.0, 700.0, 1, 'm@example.org');
    $this->mirrorRow('102', 'Kid Mismatch', '101', 620.0);
    $nums = array_column((new Recon())->run(), 'check_num');
    $this->assertContains(6, $nums);
  }

  public function testCheck6DoesNotFireWhenBalancesReconcile(): void {
    $this->mirrorRow('101', 'Reconciled Family', NULL, 0.0, 620.0, 1, 'ok@example.org');
    $this->mirrorRow('102', 'Kid Reconciled', '101', 620.0);
    $nums = array_column((new Recon())->run(), 'check_num');
    $this->assertNotContains(6, $nums);
  }

  // -------------------------------------------------------------------
  // Hourly check 4 — ACL canary: a student permissioned to more than 4
  // contacts. Counted directly off civicrm_relationship, not the ACL
  // cache, so a corrupt cache cannot hide it (§6).
  // -------------------------------------------------------------------

  public function testHourlyCheck4FiresOnFivePermissionedContacts(): void {
    FamilyBuilder::create([
      'household' => ['name' => 'Crowded Family', 'qbo_customer_id' => '801'],
      'parents' => [
        ['first_name' => 'P1', 'last_name' => 'C'], ['first_name' => 'P2', 'last_name' => 'C'],
        ['first_name' => 'P3', 'last_name' => 'C'], ['first_name' => 'P4', 'last_name' => 'C'],
        ['first_name' => 'P5', 'last_name' => 'C'],
      ],
      'students' => [['first_name' => 'Kid', 'last_name' => 'C', 'qbo_subcustomer_id' => '802']],
    ]);
    $findings = (new Recon())->runHourly();
    $nums = array_column($findings, 'check_num');
    $this->assertContains(4, $nums, '5 permissioned relationships to one student must trip the canary (§6 check 4)');
    $check4 = current(array_filter($findings, fn($f) => $f['check_num'] === 4));
    $this->assertSame('CRITICAL', $check4['severity']);
  }

  public function testHourlyCheck4DoesNotFireOnFourPermissionedContacts(): void {
    FamilyBuilder::create([
      'household' => ['name' => 'Full House Family', 'qbo_customer_id' => '901'],
      'parents' => [
        ['first_name' => 'P1', 'last_name' => 'F'], ['first_name' => 'P2', 'last_name' => 'F'],
        ['first_name' => 'P3', 'last_name' => 'F'], ['first_name' => 'P4', 'last_name' => 'F'],
      ],
      'students' => [['first_name' => 'Kid', 'last_name' => 'F', 'qbo_subcustomer_id' => '902']],
    ]);
    $nums = array_column((new Recon())->runHourly(), 'check_num');
    $this->assertNotContains(4, $nums, 'Exactly 4 permissioned contacts is the documented boundary — must not fire');
  }

  // -------------------------------------------------------------------
  // Hourly check 4b — the out-of-band permissioned-row audit (Task 7
  // carry-forward, same SQL as InvariantTest::testNoOutOfBandPermissionedRows).
  // Grouped under check_num 4 with the ACL canary (§6 amendment names it
  // "4b" but the findings table's check_num column is a plain INT — see
  // the comment on Recon::runHourly() for why it shares check_num 4
  // rather than getting its own number).
  // -------------------------------------------------------------------

  public function testHourlyCheck4bFiresOnOutOfBandPermissionedRow(): void {
    $a = Contact::create(FALSE)->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'OOB')->addValue('last_name', 'Parent')->execute()->first()['id'];
    $b = Contact::create(FALSE)->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'OOB')->addValue('last_name', 'Child')->execute()->first()['id'];
    $childOfTypeId = RelationshipType::get(FALSE)
      ->addWhere('name_a_b', '=', 'Child of')
      ->execute()->single()['id'];

    // Raw SQL, deliberately bypassing Api4 Relationship::create() (and
    // therefore boosterportal.php's hook_civicrm_pre write-time guard) —
    // this is exactly the "direct SQL, import, or restore" scenario the
    // audit exists to catch.
    \CRM_Core_DAO::executeQuery(
      'INSERT INTO civicrm_relationship
        (contact_id_a, contact_id_b, relationship_type_id, is_active, is_permission_a_b, is_permission_b_a)
       VALUES (%1, %2, %3, 1, %4, 0)',
      [1 => [$a, 'Integer'], 2 => [$b, 'Integer'], 3 => [$childOfTypeId, 'Integer'],
       4 => [\CRM_Contact_BAO_Relationship::VIEW, 'Integer']]);

    $findings = (new Recon())->runHourly();
    $nums = array_column($findings, 'check_num');
    $this->assertContains(4, $nums, 'Out-of-band permissioned "Child of" row must trip check 4b');
    $titles = array_column($findings, 'title');
    $this->assertContains('Permissioned relationship outside the portal model', $titles);
    $oob = current(array_filter($findings, fn($f) => $f['title'] === 'Permissioned relationship outside the portal model'));
    $this->assertSame('CRITICAL', $oob['severity']);
  }

  public function testHourlyCheck4bDoesNotFireOnPortalParentOfRow(): void {
    // A normal, correctly-directed Portal_Parent_of edge (as FamilyBuilder
    // creates for every fixture) must NOT trip the out-of-band audit —
    // otherwise every hourly run on a healthy site would falsely page.
    FamilyBuilder::create([
      'household' => ['name' => 'Normal Edge Family', 'qbo_customer_id' => '951'],
      'parents' => [['first_name' => 'N', 'last_name' => 'Normal']],
      'students' => [['first_name' => 'Kid', 'last_name' => 'Normal', 'qbo_subcustomer_id' => '952']],
    ]);
    $titles = array_column((new Recon())->runHourly(), 'title');
    $this->assertNotContains('Permissioned relationship outside the portal model', $titles);
  }

  // -------------------------------------------------------------------
  // ReconFinding entity permission gate (Deliverable 5's security
  // requirement, beyond the plan's own text): findings contain
  // cross-family data, so a parent holding only 'access CiviCRM' must be
  // refused; an admin session must be allowed through.
  // -------------------------------------------------------------------

  public function testReconFindingGetDeniedForAccessCiviCRMOnly(): void {
    $contactId = Contact::create(FALSE)->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Parent')->addValue('last_name', 'OnlyAccessCiviCRM')->execute()->first()['id'];
    $this->createLoggedInUserFor($contactId);
    \CRM_Core_Config::singleton()->userPermissionClass->permissions = ['access CiviCRM'];

    $this->expectException(\Civi\API\Exception\UnauthorizedException::class);
    \civicrm_api4('ReconFinding', 'get', ['checkPermissions' => TRUE]);
  }

  public function testReconFindingGetAllowedForAdmin(): void {
    $contactId = Contact::create(FALSE)->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Webmaster')->addValue('last_name', 'Admin')->execute()->first()['id'];
    $this->createLoggedInUserFor($contactId);
    \CRM_Core_Config::singleton()->userPermissionClass->permissions = [
      'access CiviCRM', 'administer CiviCRM', 'access CiviCRM backend and API',
    ];

    $result = \civicrm_api4('ReconFinding', 'get', ['checkPermissions' => TRUE]);
    $this->assertInstanceOf(\Civi\Api4\Generic\Result::class, $result);
  }

  // -------------------------------------------------------------------
  // Persistence and email-skip behaviour.
  // -------------------------------------------------------------------

  public function testFindingsArePersistedToTheFindingTable(): void {
    $this->mirrorRow('101', 'Mismatch Family', NULL, 0.0, 700.0, 1, 'm@example.org');
    $this->mirrorRow('102', 'Kid Mismatch', '101', 620.0);
    (new Recon())->run();
    $stored = (int) \CRM_Core_DAO::singleValueQuery(
      "SELECT COUNT(*) FROM boosterportal_recon_finding WHERE check_num = 6");
    $this->assertSame(1, $stored, 'Check 6 finding must be persisted to boosterportal_recon_finding');
  }

  public function testRerunReplacesStalePersistedFindingsForTheSameCheck(): void {
    $this->mirrorRow('101', 'Mismatch Family', NULL, 0.0, 700.0, 1, 'm@example.org');
    $this->mirrorRow('102', 'Kid Mismatch', '101', 620.0);
    (new Recon())->run();

    // Fix the mismatch and re-run: the stale check-6 row must be cleared,
    // not left behind alongside nothing new.
    \CRM_Core_DAO::executeQuery('UPDATE boosterportal_qbo_customer SET balance_with_jobs = 620.0 WHERE qbo_id = %1',
      [1 => ['101', 'String']]);
    (new Recon())->run();

    $stored = (int) \CRM_Core_DAO::singleValueQuery(
      "SELECT COUNT(*) FROM boosterportal_recon_finding WHERE check_num = 6");
    $this->assertSame(0, $stored, 'A resolved check must not leave a stale finding row behind after re-run');
  }

  /**
   * emailCritical() must not throw (or otherwise break the run) when no
   * webmaster email is configured — the default in a fresh test DB.
   * Actual delivery is verified live against Mailpit, not here (see the
   * Task 13 report) — this only proves the "skip silently" branch is safe.
   */
  public function testRunDoesNotThrowWithCriticalFindingsAndNoWebmasterEmailConfigured(): void {
    $default = \Civi::settings()->get('boosterportal_webmaster_email');
    $this->assertSame('', $default, 'Sanity: test DB must start with no webmaster email configured');

    FamilyBuilder::create([
      'household' => ['name' => 'Wronged Family 2', 'qbo_customer_id' => '111'],
      'parents' => [['first_name' => 'W', 'last_name' => 'Wronged2', 'email' => 'w2@example.org']],
      'students' => [['first_name' => 'Kid', 'last_name' => 'Wronged2', 'qbo_subcustomer_id' => '112']],
    ]);
    $this->mirrorRow('111', 'Wronged Family 2', NULL, 0.0, 0.0, 1, 'w2@example.org');
    $this->mirrorRow('112', 'Kid Wronged2', '999', 0.0);
    $this->mirrorRow('999', 'Other Family 2', NULL, 0.0, 0.0);

    $findings = (new Recon())->run();
    $nums = array_column($findings, 'check_num');
    $this->assertContains(1, $nums, 'Sanity: this fixture must actually produce a CRITICAL finding');
  }

  /**
   * Actually exercises emailCritical()'s CRM_Utils_Mail::send() call path
   * (webmaster email SET, a CRITICAL finding present) rather than only the
   * "skip silently" branch above. This is the test that would have caught
   * the "Argument #1 ($params) could not be passed by reference" bug found
   * live against Mailpit (Task 13 report) — CRM_Utils_Mail::send() declares
   * its first parameter `array &$params`, which an inline array literal
   * cannot satisfy. ddev's mail capture means this is a real, safe send in
   * the dev/test environment (Mailpit), not a mock.
   */
  public function testRunSendsEmailWithoutThrowingWhenCriticalFindingsExistAndWebmasterEmailIsSet(): void {
    \Civi::settings()->set('boosterportal_webmaster_email', 'webmaster-recontest@example.org');

    FamilyBuilder::create([
      'household' => ['name' => 'Wronged Family 3', 'qbo_customer_id' => '121'],
      'parents' => [['first_name' => 'W', 'last_name' => 'Wronged3', 'email' => 'w3@example.org']],
      'students' => [['first_name' => 'Kid', 'last_name' => 'Wronged3', 'qbo_subcustomer_id' => '122']],
    ]);
    $this->mirrorRow('121', 'Wronged Family 3', NULL, 0.0, 0.0, 1, 'w3@example.org');
    $this->mirrorRow('122', 'Kid Wronged3', '999', 0.0);
    $this->mirrorRow('999', 'Other Family 3', NULL, 0.0, 0.0);

    $findings = (new Recon())->run();
    $nums = array_column($findings, 'check_num');
    $this->assertContains(1, $nums, 'Sanity: this fixture must actually produce a CRITICAL finding');
  }

  // -------------------------------------------------------------------
  // Check 9 — a portal login with nothing to show. The population is
  // "accounts that can sign in", which is not the same as "parents": the
  // webmaster's own account has a civicrm_uf_match row too, from the first
  // time it opened CiviCRM, and it will never have a student.
  // -------------------------------------------------------------------

  // The same list as the boosterportal_non_portal_roles setting's default.
  private const STAFF_ROLES = ['administrator', 'editor', 'author', 'contributor',
    'booster_board', 'booster_volunteer', 'booster_webmaster'];

  public function testCheckNineIgnoresStaffAccounts(): void {
    foreach (self::STAFF_ROLES as $role) {
      $this->assertFalse(Recon::isPortalLoginAccount([$role], self::STAFF_ROLES),
        "An account holding '{$role}' is staff, not a band parent, and must not be reported as a parent with no students.");
    }
  }

  public function testCheckNineIgnoresAnAccountThatIsBothStaffAndSomethingElse(): void {
    // A board member who is also, separately, a parent signs in through SSO
    // with THIS account and through a magic link with a different one. Holding
    // any staff role at all is enough to leave this account out of the report.
    $this->assertFalse(Recon::isPortalLoginAccount(['parent', 'booster_volunteer'], self::STAFF_ROLES));
  }

  public function testCheckNineExaminesEverythingThatIsNotStaff(): void {
    // The exclusion is a denylist, so anything unrecognised stays IN the
    // report. That is the direction an alarm should fail in: a role somebody
    // forgot to add to the setting shows up as a false positive that can be
    // seen and fixed, rather than as a real parent quietly dropping out.
    $this->assertTrue(Recon::isPortalLoginAccount(['parent'], self::STAFF_ROLES));
    $this->assertTrue(Recon::isPortalLoginAccount(['some_role_invented_next_year'], self::STAFF_ROLES));
    // No roles found at all — which is also what the headless harness sees,
    // since there is no CMS in that process to ask.
    $this->assertTrue(Recon::isPortalLoginAccount([], self::STAFF_ROLES));
    // Roles come from CMS user meta, so the list is not structurally
    // guaranteed. Junk is ignored rather than interpreted.
    $this->assertTrue(Recon::isPortalLoginAccount([NULL, 42, ['nested']], self::STAFF_ROLES));
  }

  public function testCheckNineStillReportsAPortalLoginWithNoStudents(): void {
    // The check must keep doing its actual job: this is a parent whose family
    // was set up wrong, who would sign in to an empty dashboard.
    $orphan = Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Orla')
      ->addValue('last_name', 'Orphan')
      ->execute()->first();
    \Civi\Api4\UFMatch::create(FALSE)
      ->addValue('uf_id', 91500 + $orphan['id'])
      ->addValue('contact_id', $orphan['id'])
      ->addValue('uf_name', 'orla.orphan@example.org')
      ->execute();

    $nine = array_values(array_filter((new Recon())->run(),
      fn($finding) => $finding['check_num'] === 9));

    $this->assertCount(1, $nine, 'Check 9 must still report a portal login with no students: ' . json_encode($nine));
    $this->assertSame('WARNING', $nine[0]['severity'],
      'Check 9 is somebody signing in to an empty page, not a data-integrity emergency.');
    $this->assertStringContainsString('orla.orphan@example.org', $nine[0]['detail'],
      'The finding must name the account, or nobody can act on it.');
  }

}
