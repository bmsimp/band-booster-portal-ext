<?php
namespace Civi\Boosterportal;

use Civi\Api4\Contact;
use Civi\Api4\Email;
use Civi\Api4\Relationship;
use Civi\Test;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

class FamilyBuilderTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return Test::headless()->installMe(__DIR__)->apply();
  }

  public function testBuildFamilyWiresQboIdsAndPermissionedEdges(): void {
    $fam = FamilyBuilder::create([
      'household' => ['name' => 'Smith Family', 'qbo_customer_id' => '101'],
      'parents' => [['first_name' => 'Pat', 'last_name' => 'Smith', 'email' => 'pat@example.org']],
      'students' => [['first_name' => 'Sam', 'last_name' => 'Smith', 'qbo_subcustomer_id' => '102']],
    ]);

    $hh = Contact::get(FALSE)->addWhere('id', '=', $fam['household_id'])
      ->addSelect('Booster_QBO.qbo_customer_id')->execute()->single();
    $this->assertSame('101', $hh['Booster_QBO.qbo_customer_id']);

    $student = Contact::get(FALSE)->addWhere('id', '=', $fam['student_ids'][0])
      ->addSelect('Booster_QBO_Student.qbo_subcustomer_id')->execute()->single();
    $this->assertSame('102', $student['Booster_QBO_Student.qbo_subcustomer_id']);

    // Direct permissioned parent→student edge: parent (a) may VIEW student (b), not edit.
    $rel = Relationship::get(FALSE)
      ->addWhere('contact_id_a', '=', $fam['parent_ids'][0])
      ->addWhere('contact_id_b', '=', $fam['student_ids'][0])
      ->execute()->single();
    $this->assertEquals(\CRM_Contact_BAO_Relationship::VIEW, $rel['is_permission_a_b']);
    $this->assertEquals(\CRM_Contact_BAO_Relationship::NONE, $rel['is_permission_b_a']);

    // Parent and student are Household members (billing anchor, no ACL role — §4.4).
    $memberCount = Relationship::get(FALSE)
      ->addWhere('contact_id_b', '=', $fam['household_id'])
      ->addWhere('relationship_type_id:name', '=', 'Household Member of')
      ->execute()->count();
    $this->assertSame(2, $memberCount);
  }

  public function testMultipleParentsAndStudentsGetFullyCrossedPermissionedEdges(): void {
    $fam = FamilyBuilder::create([
      'household' => ['name' => 'Jones Family'],
      'parents' => [
        ['first_name' => 'Jo', 'last_name' => 'Jones'],
        ['first_name' => 'Al', 'last_name' => 'Jones'],
      ],
      'students' => [
        ['first_name' => 'Kim', 'last_name' => 'Jones'],
        ['first_name' => 'Lee', 'last_name' => 'Jones'],
      ],
    ]);

    $this->assertCount(2, $fam['parent_ids']);
    $this->assertCount(2, $fam['student_ids']);

    $edges = Relationship::get(FALSE)
      ->addWhere('contact_id_a', 'IN', $fam['parent_ids'])
      ->addWhere('contact_id_b', 'IN', $fam['student_ids'])
      ->addWhere('relationship_type_id:name', '=', 'Portal_Parent_of')
      ->addSelect('contact_id_a', 'contact_id_b', 'is_permission_a_b', 'is_permission_b_a')
      ->execute();
    $this->assertSame(4, $edges->count());

    // Every parent x student pair must be present exactly once, each view-only.
    $pairs = [];
    foreach ($edges as $edge) {
      $pairs[] = $edge['contact_id_a'] . ':' . $edge['contact_id_b'];
      $this->assertEquals(\CRM_Contact_BAO_Relationship::VIEW, $edge['is_permission_a_b']);
      $this->assertEquals(\CRM_Contact_BAO_Relationship::NONE, $edge['is_permission_b_a']);
    }
    $expectedPairs = [];
    foreach ($fam['parent_ids'] as $parentId) {
      foreach ($fam['student_ids'] as $studentId) {
        $expectedPairs[] = "$parentId:$studentId";
      }
    }
    sort($pairs);
    sort($expectedPairs);
    $this->assertSame($expectedPairs, $pairs);

    $memberCount = Relationship::get(FALSE)
      ->addWhere('contact_id_b', '=', $fam['household_id'])
      ->addWhere('relationship_type_id:name', '=', 'Household Member of')
      ->execute()->count();
    $this->assertSame(4, $memberCount);
  }

  public function testParentWithNoEmailCreatesFineWithNoEmailRow(): void {
    $fam = FamilyBuilder::create([
      'household' => ['name' => 'NoEmail Family'],
      'parents' => [['first_name' => 'Noe', 'last_name' => 'Mail']],
      'students' => [['first_name' => 'Stu', 'last_name' => 'Dent']],
    ]);

    $emailCount = Email::get(FALSE)
      ->addWhere('contact_id', '=', $fam['parent_ids'][0])
      ->execute()->count();
    $this->assertSame(0, $emailCount);
  }

  public function testMissingHouseholdNameThrowsAndCreatesNoContacts(): void {
    $before = Contact::get(FALSE)->selectRowCount()->execute()->count();

    try {
      FamilyBuilder::create([
        'household' => ['qbo_customer_id' => '999'],
        'parents' => [['first_name' => 'Ghost', 'last_name' => 'Parent']],
        'students' => [['first_name' => 'Ghost', 'last_name' => 'Student']],
      ]);
      $this->fail('Expected InvalidArgumentException was not thrown.');
    }
    catch (\InvalidArgumentException $e) {
      // expected
    }

    $after = Contact::get(FALSE)->selectRowCount()->execute()->count();
    $this->assertSame($before, $after, 'A failed FamilyBuilder::create() call must not leave ghost contacts behind.');
  }

  /**
   * Fast-follow 2: testMissingHouseholdNameThrowsAndCreatesNoContacts() above
   * proves the up-front validate() guard — nothing is written because the
   * failure happens BEFORE doCreate() runs at all. This test proves the
   * other half: FamilyBuilder::create() wraps doCreate() in
   * \CRM_Core_Transaction::create()->run(...), so a failure DURING the
   * write — after some contacts already exist mid-family — must roll all of
   * them back too, not just skip the one that failed.
   *
   * There's no data-driven way to make a validate()-clean student fail at
   * write time, so this interposes directly on the same hook_civicrm_pre
   * event CRM_Utils_Hook::pre() dispatches for every Contact write
   * (Civi\Core\Event\PreEvent, entity = the contact_type, e.g. 'Individual'
   * for both students and parents; 'Household' for the household itself —
   * see CRM_Contact_BAO_Contact::create()). A temporary listener throws a
   * \CRM_Core_Exception on the SECOND Individual create (household is
   * 'Household' and doesn't count; student 1 succeeds; student 2 is where
   * this fires, matching the "student 2 breaks at write time" scenario) and
   * is removed in finally{} so it can never leak into another test.
   */
  public function testMidWriteFailureRollsBackWholeFamilyAndRetrySucceeds(): void {
    $individualCreateCount = 0;
    $listener = function (\Civi\Core\Event\PreEvent $event) use (&$individualCreateCount) {
      if ($event->entity === 'Individual' && $event->action === 'create') {
        $individualCreateCount++;
        if ($individualCreateCount === 2) {
          throw new \CRM_Core_Exception('Simulated write failure for rollback test (Task 11 fast-follow).');
        }
      }
    };

    $def = [
      'household' => ['name' => 'Rollback Family', 'qbo_customer_id' => '901'],
      'parents' => [['first_name' => 'Rory', 'last_name' => 'Rollback', 'email' => 'rory@example.org']],
      'students' => [
        ['first_name' => 'Riley', 'last_name' => 'Rollback', 'qbo_subcustomer_id' => '902'],
        ['first_name' => 'Robin', 'last_name' => 'Rollback', 'qbo_subcustomer_id' => '903'],
      ],
    ];

    $before = Contact::get(FALSE)->selectRowCount()->execute()->count();

    \Civi::dispatcher()->addListener('hook_civicrm_pre', $listener);
    try {
      $threw = FALSE;
      try {
        FamilyBuilder::create($def);
      }
      catch (\CRM_Core_Exception $e) {
        $threw = TRUE;
        $this->assertStringContainsString('Simulated write failure', $e->getMessage());
      }
      $this->assertTrue($threw, 'Expected the interposed hook to throw and abort the write.');
    }
    finally {
      \Civi::dispatcher()->removeListener('hook_civicrm_pre', $listener);
    }

    $after = Contact::get(FALSE)->selectRowCount()->execute()->count();
    $this->assertSame($before, $after,
      'A mid-write throw must roll back the WHOLE family (household + student 1 too), not just skip student 2.');
    $leftover = Contact::get(FALSE)
      ->addWhere('Booster_QBO.qbo_customer_id', '=', '901')
      ->execute();
    $this->assertSame(0, $leftover->count(), 'The partially-built household must not survive the rollback.');

    // Clean retry (listener removed) succeeds — proves the rollback left no
    // half-written state that would block a subsequent, correct run.
    $result = FamilyBuilder::create($def);
    $this->assertNotEmpty($result['household_id']);
    $this->assertCount(2, $result['student_ids']);
    $hh = Contact::get(FALSE)->addWhere('Booster_QBO.qbo_customer_id', '=', '901')->execute();
    $this->assertSame(1, $hh->count());
  }

}
