<?php
namespace Civi\Boosterportal;

use Civi\Api4\Contact;
use Civi\Api4\Relationship;
use Civi\Test;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * §9: fixture with two unrelated families; assert that a logged-in parent from
 * family A can reach nothing belonging to family B, on every parent-reachable
 * entity. THIS IS THE TEST THAT FAILS THE BUILD.
 */
class AclLeakTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  private array $famA;
  private array $famB;

  /**
   * A per-run-unique string used to derive every family-B value that
   * testEveryDashboardSearchDisplayIsLeakFree() checks for as a leak signal.
   * Security review N4: fixed short strings like '301'/'302'/'Beta' are
   * collision-prone substrings (numeric ones can match ids/weights/timestamps
   * elsewhere in a serialized result; even a word like 'Beta' could turn up in
   * unrelated UI text) — every one of family B's distinguishing values below
   * is derived from this instead, so there are no fixed-string sentinels left.
   */
  private string $sentinel;
  private string $famBQboCustomerId;
  private string $famBQboSubcustomerId;
  private string $famBLastName;

  public function setUpHeadless(): CiviEnvBuilder {
    return Test::headless()->installMe(__DIR__)->apply();
  }

  public function setUp(): void {
    parent::setUp();
    $this->sentinel = bin2hex(random_bytes(8));
    $this->famBQboCustomerId = 'Q' . $this->sentinel . '0';
    $this->famBQboSubcustomerId = 'Q' . $this->sentinel . '1';
    $this->famBLastName = 'Zz' . $this->sentinel;

    $this->famA = FamilyBuilder::create([
      'household' => ['name' => 'Alpha Family', 'qbo_customer_id' => '201'],
      'parents' => [['first_name' => 'Ana', 'last_name' => 'Alpha', 'email' => 'ana@example.org']],
      'students' => [['first_name' => 'Avery', 'last_name' => 'Alpha', 'qbo_subcustomer_id' => '202']],
    ]);
    $this->famB = FamilyBuilder::create([
      'household' => ['name' => 'Beta Family', 'qbo_customer_id' => $this->famBQboCustomerId],
      'parents' => [['first_name' => 'Bo', 'last_name' => $this->famBLastName, 'email' => "bo-{$this->sentinel}@example.org"]],
      'students' => [['first_name' => 'Blake', 'last_name' => $this->famBLastName, 'qbo_subcustomer_id' => $this->famBQboSubcustomerId]],
    ]);

    // Log in as parent A with ONLY the parent-tier permission set (Task 4).
    $this->createLoggedInUserFor($this->famA['parent_ids'][0]);
    \CRM_Core_Config::singleton()->userPermissionClass->permissions = ['access CiviCRM'];
  }

  private function createLoggedInUserFor(int $contactId): void {
    $session = \CRM_Core_Session::singleton();
    $session->set('userID', $contactId);
    // uf_id derived from $contactId (not a fixed 99001): several tests below
    // log in as more than one contact in turn (multi-household fixtures),
    // and civicrm_uf_match.uf_id is unique — a fixed value would collide on
    // the second call.
    \Civi\Api4\UFMatch::create(FALSE)
      ->addValue('uf_id', 90000 + $contactId)
      ->addValue('contact_id', $contactId)
      ->addValue('uf_name', "acltest-{$contactId}@example.org")
      ->execute();
  }

  private function famBIds(): array {
    return array_merge(
      [$this->famB['household_id']],
      $this->famB['parent_ids'],
      $this->famB['student_ids']
    );
  }

  public function testContactsOfOtherFamilyAreInvisible(): void {
    $visible = Contact::get(TRUE)->execute()->column('id');
    $this->assertSame([], array_values(array_intersect($visible, $this->famBIds())),
      'Parent A can see family B contacts — ACL LEAK');
    // Sanity: the ACL actually grants something (own student visible),
    // so an empty result cannot silently pass the leak assertions.
    $this->assertContains($this->famA['student_ids'][0], $visible,
      'Parent A cannot see own student — fixture or ACL broken, leak test is vacuous');
  }

  public function testStudentQboFieldOfOtherFamilyIsInvisible(): void {
    $rows = Contact::get(TRUE)
      ->addSelect('id', 'Booster_QBO_Student.qbo_subcustomer_id')
      ->addWhere('Booster_QBO_Student.qbo_subcustomer_id', 'IS NOT NULL')
      ->execute()->column('Booster_QBO_Student.qbo_subcustomer_id');
    $this->assertNotContains($this->famBQboSubcustomerId, $rows, 'Family B sub-customer id leaked');
    $this->assertContains('202', $rows, 'Own student QBO id missing — test vacuous');
  }

  public function testRelationshipsOfOtherFamilyAreInvisible(): void {
    // Parents currently can't see even their OWN relationships in bulk queries:
    // the default Relationship ACL clause (CRM_Core_DAO::addSelectWhereClause())
    // requires BOTH contact_id_a and contact_id_b to independently pass the
    // Contact ACL check, and a parent has no "view my own contact" grant under
    // the minimal access-CiviCRM-only permission set used here — so contact_id_a
    // (the parent themself) never passes, even though contact_id_b (the
    // student) does. That's an over-restriction, not a leak: it fails safe.
    // Revisit if/when Task 17's dashboard needs to query Relationship directly
    // rather than Contact.
    $this->assertSame([], Relationship::get(TRUE)->execute()->getArrayCopy(),
      'Parent A can see relationship rows — if this is no longer [], check whether it is still the known self-visibility over-restriction or an actual family B leak');
  }

  public function testEveryDashboardSearchDisplayIsLeakFree(): void {
    // Every SearchKit display in the system (the dashboard ships as managed
    // displays, so they are all present headlessly). Run each as parent A with
    // permission checks ON and assert none of family B's sentinel VALUES
    // appear anywhere in the result output.
    //
    // Deliberately matching string sentinel values here, not raw contact-id
    // numbers: an earlier version grepped the flattened JSON for
    // '"cid":'.$famBContactId and a '"id":\s*'.$famBContactId regex, which
    // produced a false positive when an unrelated small integer (e.g. an
    // OptionValue id from a core admin display) happened to equal a family-B
    // contact id — a nondeterministic failure mode unrelated to any actual
    // leak. Every value below is entirely derived from $this->sentinel
    // (security review N4): no fixed short strings remain, so nothing here
    // can collide with an unrelated id, weight, timestamp, or ordinary word
    // that happens to appear in some other display's output.
    $sentinels = [$this->sentinel, $this->famBQboCustomerId, $this->famBQboSubcustomerId, $this->famBLastName];

    $displays = \Civi\Api4\SearchDisplay::get(FALSE)
      ->addSelect('name', 'saved_search_id.name')
      ->execute();
    $runCount = 0;
    $deniedCount = 0;
    $erroredCount = 0;
    $swept = [];
    foreach ($displays as $display) {
      try {
        $result = \civicrm_api4('SearchDisplay', 'run', [
          'checkPermissions' => TRUE,
          'savedSearch' => $display['saved_search_id.name'],
          'display' => $display['name'],
        ]);
      }
      catch (\Civi\API\Exception\UnauthorizedException $e) {
        // Parent A lacks permission to run this display at all (most of the
        // displays presently in the system are CiviCRM's own admin screens —
        // Manage ACLs, Mailings, etc — not part of this extension's dashboard,
        // which doesn't exist until Task 17). A hard permission denial is a
        // stronger guarantee than an empty leak-free result: nothing ran, so
        // nothing could have leaked. Move on to the next display.
        $deniedCount++;
        continue;
      }
      catch (\Throwable $e) {
        // The display failed for this parent. The cause varies and is always
        // core's, not this extension's: CiviCRM 6.17.2 ships three saved
        // searches whose own SQL will not build ("Invalid field ..."),
        // civi_member's MembershipStatusLinksProvider auto-vivifies
        // title-less tasks for a user who may not edit membership statuses
        // (so GetSearchTasks' closing usort on $task['title'] trips), and
        // core raises deprecation notices that phpunit.xml.dist turns into
        // exceptions.
        //
        // For THIS test all of those have the same consequence, and it is
        // the only one that matters: the call threw, so no rows reached the
        // parent, so this display leaked nothing to them.
        //
        // Tolerated as a rule rather than enumerated as a skip-list of
        // display names: the list belongs to core, it changes with every
        // CiviCRM upgrade, and a stale entry silently stops sweeping a
        // display that still exists. What stops this branch from quietly
        // hollowing out the sweep is the assertion below -- the extension's
        // OWN dashboard display must be among the ones that actually ran.
        $erroredCount++;
        continue;
      }
      $runCount++;
      $swept[] = $display['name'];
      $flat = json_encode($result);
      foreach ($sentinels as $sentinel) {
        $this->assertStringNotContainsString($sentinel, $flat,
          "Display {$display['name']} leaks family B sentinel value '{$sentinel}'");
      }
    }

    // N4: print the coverage unconditionally, not just on failure, so CI
    // output shows how much of the sweep actually exercised checkPermissions
    // logic even on a green run.
    fwrite(STDOUT, sprintf(
      "[AclLeakTest] SearchDisplay sweep: %d run and checked, %d denied outright, "
      . "%d failed for this parent (of %d displays total)\n",
      $runCount, $deniedCount, $erroredCount, $displays->count()
    ));

    // Task 17: the dashboard's own displays (Your_Students / your-students-table)
    // now exist as managed entities, and parent A holds 'access CiviCRM' plus a
    // real Portal_Parent_of edge to their own student — so at least this one
    // display must actually run (not be skipped as UnauthorizedException) for
    // the sweep above to have exercised any checkPermissions logic at all.
    // Tightened from assertGreaterThanOrEqual(0, ...): zero real runs would now
    // mean the sweep is vacuous — nothing was actually swept for leaks.
    // The sweep must never be allowed to go vacuous. $runCount > 0 alone is a
    // weak guard now that any core failure is tolerated above, so name the one
    // display this project actually owns: the dashboard table, which parent A
    // can reach (they hold access CiviCRM and a real Portal_Parent_of edge to
    // their own student). If it stops being swept, the tolerant catch has
    // started hiding something and this test says so.
    $this->assertContains('your-students-table', $swept,
      "The dashboard's own SearchDisplay was not among the {$runCount} displays that ran and were checked "
      . "({$deniedCount} denied, {$erroredCount} failed) -- the sweep is no longer covering the display that matters.");
  }

  /**
   * Task 12 Step 6 (adapted): the plan's literal derivation —
   * Contact::get(TRUE)->addJoin('Relationship AS rel', ...) — was tried
   * empirically against this exact fixture and returns ZERO rows, even for
   * parent A's own student. Root cause: the join pulls in Relationship's
   * OWN default ACL clause (CRM_Core_DAO::addSelectWhereClause()), which
   * requires BOTH contact_id_a and contact_id_b to independently pass the
   * Contact ACL check — and a parent has no grant to see their own contact
   * row (testRelationshipsOfOtherFamilyAreInvisible() above documents the
   * same effect: Relationship::get(TRUE) is always [], even for family A).
   *
   * FamilyResolver::studentsOf() instead reads candidate student ids via
   * direct SQL mirroring boosterportal_civicrm_aclWhereClause()'s own
   * subquery, then verifies them via a real Contact::get(TRUE) call (belt
   * and braces — only the intersection is trusted). This test is that
   * derivation's headless, QBO-free coverage: assert it returns exactly
   * parent A's own student and nothing of family B.
   */
  public function testGetMyBalanceSeesOnlyOwnStudents(): void {
    $students = FamilyResolver::studentsOf($this->famA['parent_ids'][0]);
    $qboIds = array_column($students, 'qbo_subcustomer_id');
    $this->assertSame(['202'], $qboIds, 'FamilyResolver::studentsOf() must return exactly family A\'s own student qbo id, nothing of family B');
  }

  /**
   * The billing household lookup (FamilyResolver::billingHouseholdsOf())
   * runs with checkPermissions FALSE — parents cannot see Household rows at
   * all (Task 7 amendment) — so it is keyed ONLY off ACL-verified student
   * ids from testGetMyBalanceSeesOnlyOwnStudents() above, never off request
   * input. Confirm it lands on family A's household and never family B's.
   */
  public function testGetMyBalanceHouseholdLookupScopedToOwnStudents(): void {
    $students = FamilyResolver::studentsOf($this->famA['parent_ids'][0]);
    $households = FamilyResolver::billingHouseholdsOf($students);
    $this->assertCount(1, $households, 'Family A has exactly one billing household');
    $household = array_values($households)[0];
    $this->assertSame('201', $household['qbo_customer_id']);
    $this->assertNotSame($this->famBQboCustomerId, $household['qbo_customer_id'],
      'Household lookup scoped to family A students must never resolve to family B\'s household');
  }

  /**
   * C1/C2 input (adversarial security review, post-Task-12): the §4.6
   * cross-check is only valid when the parent's verified student set for a
   * household equals that household's COMPLETE active student set.
   * FamilyResolver::activeStudentCountOf() is the "complete" half of that
   * comparison — a checkPermissions FALSE, COUNT-ONLY query (no contact
   * data returned, only an integer — see InvariantTest's allowlist for the
   * containment argument). Fixture: one household, two students, this
   * parent's Portal_Parent_of edge only to one of them (the other's edge is
   * neutralized post-creation, modeling a revoked/never-granted edge).
   * Assert: verified (studentsOf) sees only the edged student, but
   * activeStudentCountOf() correctly reports BOTH — i.e. the gate's inputs
   * correctly disagree, which is what makes BalanceService treat this
   * household as incomplete.
   */
  public function testBillingHouseholdsOfDetectsIncompleteHouseholdWhenSiblingUnedged(): void {
    $fam = FamilyBuilder::create([
      'household' => ['name' => 'Sibling Family', 'qbo_customer_id' => '401'],
      'parents' => [['first_name' => 'Sib', 'last_name' => 'Parent', 'email' => 'sib@example.org']],
      'students' => [
        ['first_name' => 'Edged', 'last_name' => 'Sib', 'qbo_subcustomer_id' => '402'],
        ['first_name' => 'Unedged', 'last_name' => 'Sib', 'qbo_subcustomer_id' => '403'],
      ],
    ]);
    // Neutralize the second student's edge — models a revoked/never-granted
    // Portal_Parent_of permission, which is exactly the scenario the
    // complete-set gate exists for.
    \Civi\Api4\Relationship::update(FALSE)
      ->addWhere('contact_id_a', '=', $fam['parent_ids'][0])
      ->addWhere('contact_id_b', '=', $fam['student_ids'][1])
      ->addValue('is_permission_a_b', \CRM_Contact_BAO_Relationship::NONE)
      ->execute();

    $this->createLoggedInUserFor($fam['parent_ids'][0]);
    \CRM_Core_Config::singleton()->userPermissionClass->permissions = ['access CiviCRM'];

    $students = FamilyResolver::studentsOf($fam['parent_ids'][0]);
    $this->assertSame(['402'], array_column($students, 'qbo_subcustomer_id'),
      'Only the still-edged student should be verified');

    $households = FamilyResolver::billingHouseholdsOf($students);
    $this->assertCount(1, $households);
    $household = array_values($households)[0];
    $this->assertSame('401', $household['qbo_customer_id']);
    $this->assertCount(1, $household['students'], 'Only the verified student should appear in the grouping');

    $completeCount = FamilyResolver::activeStudentCountOf($household['id']);
    $this->assertSame(2, $completeCount,
      'The household actually has 2 active students — verified (1) != complete (2) is what marks this household incomplete');
  }

  /**
   * I2/§4.4 split-family shape: the parent's OWN household is H2 (Step
   * Parent's home) but their permissioned edge reaches a student who is a
   * member of a DIFFERENT household, H1 — which also has a second, un-edged
   * student. Billing-household resolution must follow the STUDENT's own
   * household membership, never the parent's, and must still detect H1 as
   * incomplete (the un-edged sibling).
   */
  public function testBillingHouseholdsOfResolvesSplitFamilyToStudentsOwnHousehold(): void {
    $h1 = FamilyBuilder::create([
      'household' => ['name' => 'H1 Family', 'qbo_customer_id' => '501'],
      'parents' => [],
      'students' => [
        ['first_name' => 'Reachable', 'last_name' => 'H1', 'qbo_subcustomer_id' => '502'],
        ['first_name' => 'Unreachable', 'last_name' => 'H1', 'qbo_subcustomer_id' => '503'],
      ],
    ]);
    $h2 = FamilyBuilder::create([
      'household' => ['name' => 'H2 Family', 'qbo_customer_id' => '511'],
      'parents' => [['first_name' => 'Step', 'last_name' => 'Parent', 'email' => 'step@example.org']],
      'students' => [],
    ]);
    $stepParentId = $h2['parent_ids'][0];
    \Civi\Api4\Relationship::create(FALSE)
      ->addValue('contact_id_a', $stepParentId)
      ->addValue('contact_id_b', $h1['student_ids'][0])
      ->addValue('relationship_type_id:name', 'Portal_Parent_of')
      ->addValue('is_permission_a_b', \CRM_Contact_BAO_Relationship::VIEW)
      ->addValue('is_permission_b_a', \CRM_Contact_BAO_Relationship::NONE)
      ->execute();

    $this->createLoggedInUserFor($stepParentId);
    \CRM_Core_Config::singleton()->userPermissionClass->permissions = ['access CiviCRM'];

    $students = FamilyResolver::studentsOf($stepParentId);
    $this->assertSame(['502'], array_column($students, 'qbo_subcustomer_id'));

    $households = FamilyResolver::billingHouseholdsOf($students);
    $this->assertCount(1, $households);
    $household = array_values($households)[0];
    $this->assertSame('501', $household['qbo_customer_id'], 'Must resolve to H1 (the student\'s household), never H2 (the parent\'s own)');
    $this->assertNotSame('511', $household['qbo_customer_id']);

    $this->assertSame(2, FamilyResolver::activeStudentCountOf($household['id']),
      'H1 has 2 active students; only 1 is verified — incomplete');
  }

  /**
   * I2 legitimate case: a parent verified for students in TWO different,
   * each fully-visible, billing households (no split, no revoked edge —
   * just two complete families). Both must resolve as separate complete
   * households, so BalanceService never applies a permanent "partial view"
   * flag to a parent who can rightfully see everything.
   */
  public function testBillingHouseholdsOfMarksTwoSeparateCompleteHouseholdsComplete(): void {
    $fam1 = FamilyBuilder::create([
      'household' => ['name' => 'Multi H1', 'qbo_customer_id' => '801'],
      'parents' => [['first_name' => 'Multi', 'last_name' => 'Parent', 'email' => 'multi@example.org']],
      'students' => [['first_name' => 'KidOne', 'last_name' => 'H1', 'qbo_subcustomer_id' => '802']],
    ]);
    $fam2 = FamilyBuilder::create([
      'household' => ['name' => 'Multi H2', 'qbo_customer_id' => '901'],
      'parents' => [],
      'students' => [['first_name' => 'KidTwo', 'last_name' => 'H2', 'qbo_subcustomer_id' => '902']],
    ]);
    $parentId = $fam1['parent_ids'][0];
    \Civi\Api4\Relationship::create(FALSE)
      ->addValue('contact_id_a', $parentId)
      ->addValue('contact_id_b', $fam2['student_ids'][0])
      ->addValue('relationship_type_id:name', 'Portal_Parent_of')
      ->addValue('is_permission_a_b', \CRM_Contact_BAO_Relationship::VIEW)
      ->addValue('is_permission_b_a', \CRM_Contact_BAO_Relationship::NONE)
      ->execute();

    $this->createLoggedInUserFor($parentId);
    \CRM_Core_Config::singleton()->userPermissionClass->permissions = ['access CiviCRM'];

    $students = FamilyResolver::studentsOf($parentId);
    $this->assertSame(['802', '902'], array_column($students, 'qbo_subcustomer_id'));

    $households = FamilyResolver::billingHouseholdsOf($students);
    $this->assertCount(2, $households, 'Two distinct billing households expected');
    foreach ($households as $household) {
      $verifiedCount = count($household['students']);
      $completeCount = FamilyResolver::activeStudentCountOf($household['id']);
      $this->assertSame($completeCount, $verifiedCount,
        "Household {$household['qbo_customer_id']} should be complete (verified == complete)");
    }
    $qboIds = array_column($households, 'qbo_customer_id');
    sort($qboIds);
    $this->assertSame(['801', '901'], $qboIds);
  }

  /**
   * R3 (second security review): the SQL model doesn't structurally
   * prevent a single student contact from having TWO simultaneous active
   * "Household Member of" edges (a data anomaly — the importer/FamilyBuilder
   * never produce this, but nothing stops a hand-edit). Without dedup, that
   * student would be counted toward BOTH households' balances — double
   * counting real money owed. billingHouseholdsOf() assigns a dually-
   * membered student to exactly ONE household: the lower household id,
   * deterministically (rows arrive ordered by household id ascending).
   */
  public function testBillingHouseholdsOfAssignsDuallyMemberedStudentToOneHouseholdOnly(): void {
    $hLow = FamilyBuilder::create([
      'household' => ['name' => 'Low House', 'qbo_customer_id' => '1001'],
      'parents' => [['first_name' => 'Dual', 'last_name' => 'Parent', 'email' => 'dual@example.org']],
      'students' => [['first_name' => 'Shared', 'last_name' => 'Kid', 'qbo_subcustomer_id' => '1002']],
    ]);
    $hHigh = FamilyBuilder::create([
      'household' => ['name' => 'High House', 'qbo_customer_id' => '1101'],
      'parents' => [],
      'students' => [],
    ]);
    // The anomaly: give the SAME student a second, active "Household Member
    // of" edge to a SECOND household. $hLow's household contact id is lower
    // (created first, in the same transactional test), so it must win.
    \Civi\Api4\Relationship::create(FALSE)
      ->addValue('contact_id_a', $hLow['student_ids'][0])
      ->addValue('contact_id_b', $hHigh['household_id'])
      ->addValue('relationship_type_id:name', 'Household Member of')
      ->execute();
    $this->assertLessThan($hHigh['household_id'], $hLow['household_id'],
      'Fixture assumption: hLow must have the lower contact id — test is vacuous otherwise');

    $this->createLoggedInUserFor($hLow['parent_ids'][0]);
    \CRM_Core_Config::singleton()->userPermissionClass->permissions = ['access CiviCRM'];

    $students = FamilyResolver::studentsOf($hLow['parent_ids'][0]);
    $this->assertSame(['1002'], array_column($students, 'qbo_subcustomer_id'));

    $households = FamilyResolver::billingHouseholdsOf($students);
    $this->assertCount(1, $households, 'The dually-membered student must be assigned to exactly ONE household, not both');
    $only = array_values($households)[0];
    $this->assertSame('1001', $only['qbo_customer_id'], 'Lower household id must win deterministically');
  }

}
