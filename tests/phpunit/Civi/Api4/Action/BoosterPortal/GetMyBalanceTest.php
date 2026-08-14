<?php
namespace Civi\Api4\Action\BoosterPortal;

use Civi\Boosterportal\FamilyBuilder;
use Civi\Test;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * GetMyBalance's own thin-wiring behavior — the session check, the explicit
 * empty state, and graceful failure without QBO credentials (mirroring
 * RefreshMirrorTest) — exercised entirely mock-free: every path here either
 * returns before QboClient is ever touched, or fails at the same
 * credential precheck RefreshMirror uses, before QboClient is ever
 * constructed. The §4.6 balance maths itself is BalanceServiceTest's job;
 * the ACL-scoped family derivation (FamilyResolver) is AclLeakTest's job.
 * This file is only the action's own glue: login check, cache, empty state,
 * credential gate.
 *
 * ->setCheckPermissions(FALSE) throughout: these tests exercise _run()'s own
 * logic, not the entity's permissions() gate — same rationale as
 * RefreshMirrorTest. Only files under the extension's Civi/ directory are
 * scanned by InvariantTest's allowlist (tests/ is excluded), so this needs
 * no allowlist entry.
 */
class GetMyBalanceTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless(): CiviEnvBuilder {
    return Test::headless()->installMe(__DIR__)->apply();
  }

  private function logOut(): void {
    \CRM_Core_Session::singleton()->set('userID', NULL);
  }

  private function logInAs(int $contactId): void {
    $session = \CRM_Core_Session::singleton();
    $session->set('userID', $contactId);
    \Civi\Api4\UFMatch::create(FALSE)
      ->addValue('uf_id', 88500 + $contactId)
      ->addValue('contact_id', $contactId)
      ->addValue('uf_name', "test-{$contactId}@example.org")
      ->execute();
    \CRM_Core_Config::singleton()->userPermissionClass->permissions = ['access CiviCRM'];
  }

  public function testThrowsIfNotLoggedIn(): void {
    $this->logOut();
    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/not logged in/i');
    (new GetMyBalance('BoosterPortal', 'getMyBalance'))
      ->setCheckPermissions(FALSE)
      ->execute();
  }

  public function testReturnsExplicitEmptyStateWhenParentHasNoStudents(): void {
    // A contact with no Portal_Parent_of relationship at all: FamilyResolver
    // must return an empty student list, and GetMyBalance must surface an
    // explicit empty state — NULL balance, never a zero that could read as
    // "paid up" — without ever touching QboClient (no QBO credentials exist
    // in this test DB, so reaching QboClient would itself throw).
    $lonely = \Civi\Api4\Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Lonely')
      ->addValue('last_name', 'Parent')
      ->execute()->first()['id'];
    $this->logInAs($lonely);

    $result = (new GetMyBalance('BoosterPortal', 'getMyBalance'))
      ->setCheckPermissions(FALSE)
      ->execute()->first();

    $this->assertTrue($result['empty']);
    $this->assertNull($result['balance']);
    $this->assertFalse($result['flagged']);
    $this->assertSame([], $result['students']);
    $this->assertSame([], $result['invoices']);
  }

  public function testFailsGracefullyWithoutCredentials(): void {
    // A real family (so derivation succeeds and the action gets all the way
    // to needing QBO) but no QBO credentials configured — the out-of-the-box
    // state before Task 9's OAuth connect flow has ever been run against
    // this test DB. Must fail with a clear CRM_Core_Exception mentioning the
    // runbook, not a raw SDK/HTTP fatal.
    $fam = FamilyBuilder::create([
      'household' => ['name' => 'Cred Test Family', 'qbo_customer_id' => '501'],
      'parents' => [['first_name' => 'Cred', 'last_name' => 'Tester', 'email' => 'cred@example.org']],
      'students' => [['first_name' => 'Kid', 'last_name' => 'Tester', 'qbo_subcustomer_id' => '502']],
    ]);
    $this->logInAs($fam['parent_ids'][0]);

    $this->expectException(\CRM_Core_Exception::class);
    $this->expectExceptionMessageMatches('/quickbooks/i');
    (new GetMyBalance('BoosterPortal', 'getMyBalance'))
      ->setCheckPermissions(FALSE)
      ->execute();
  }

}
