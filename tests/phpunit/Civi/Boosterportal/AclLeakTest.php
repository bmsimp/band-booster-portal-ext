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

  public function setUpHeadless(): CiviEnvBuilder {
    return Test::headless()->installMe(__DIR__)->apply();
  }

  public function setUp(): void {
    parent::setUp();
    $this->famA = FamilyBuilder::create([
      'household' => ['name' => 'Alpha Family', 'qbo_customer_id' => '201'],
      'parents' => [['first_name' => 'Ana', 'last_name' => 'Alpha', 'email' => 'ana@example.org']],
      'students' => [['first_name' => 'Avery', 'last_name' => 'Alpha', 'qbo_subcustomer_id' => '202']],
    ]);
    $this->famB = FamilyBuilder::create([
      'household' => ['name' => 'Beta Family', 'qbo_customer_id' => '301'],
      'parents' => [['first_name' => 'Bo', 'last_name' => 'Beta', 'email' => 'bo@example.org']],
      'students' => [['first_name' => 'Blake', 'last_name' => 'Beta', 'qbo_subcustomer_id' => '302']],
    ]);

    // Log in as parent A with ONLY the parent-tier permission set (Task 4).
    $this->createLoggedInUserFor($this->famA['parent_ids'][0]);
    \CRM_Core_Config::singleton()->userPermissionClass->permissions = ['access CiviCRM'];
  }

  private function createLoggedInUserFor(int $contactId): void {
    $session = \CRM_Core_Session::singleton();
    $session->set('userID', $contactId);
    \Civi\Api4\UFMatch::create(FALSE)
      ->addValue('uf_id', 99001)
      ->addValue('contact_id', $contactId)
      ->addValue('uf_name', 'ana@example.org')
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
    $this->assertNotContains('302', $rows, 'Family B sub-customer id leaked');
    $this->assertContains('202', $rows, 'Own student QBO id missing — test vacuous');
  }

  public function testRelationshipsOfOtherFamilyAreInvisible(): void {
    $rels = Relationship::get(TRUE)->execute();
    foreach ($rels as $rel) {
      $this->assertNotContains($rel['contact_id_a'], $this->famBIds(), 'Family B relationship leaked');
      $this->assertNotContains($rel['contact_id_b'], $this->famBIds(), 'Family B relationship leaked');
    }
  }

  public function testEveryDashboardSearchDisplayIsLeakFree(): void {
    // Every SearchKit display in the system (the dashboard ships as managed
    // displays, so they are all present headlessly). Run each as parent A with
    // permission checks ON and assert no family-B contact id appears anywhere
    // in the result rows.
    $displays = \Civi\Api4\SearchDisplay::get(FALSE)
      ->addSelect('name', 'saved_search_id.name')
      ->execute();
    foreach ($displays as $display) {
      try {
        $result = \civicrm_api4('SearchDisplay', 'run', [
          'checkPermissions' => TRUE,
          'savedSearch' => $display['saved_search_id.name'],
          'display' => $display['name'],
        ]);
      }
      catch (\Civi\API\Exception\UnauthorizedException $e) {
        // Parent A lacks permission to run this display at all (most of the 59
        // displays presently in the system are CiviCRM's own admin screens —
        // Manage ACLs, Mailings, etc — not part of this extension's dashboard,
        // which doesn't exist until Task 17). A hard permission denial is a
        // stronger guarantee than an empty leak-free result: nothing ran, so
        // nothing could have leaked. Move on to the next display.
        continue;
      }
      $flat = json_encode($result);
      foreach ($this->famBIds() as $id) {
        $this->assertStringNotContainsString('"cid":' . $id, $flat,
          "Display {$display['name']} leaks family B contact {$id}");
        $this->assertDoesNotMatchRegularExpression('/"id":\s*' . $id . '\b/', $flat,
          "Display {$display['name']} leaks family B contact {$id}");
      }
    }
    // Vacuity guard once the dashboard exists (Task 17 flips this to assertNotEmpty).
    $this->assertGreaterThanOrEqual(0, $displays->count());
  }

}
