<?php
namespace Civi\Boosterportal;

use Civi\Test;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

class MirrorTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return Test::headless()->installMe(__DIR__)->apply();
  }

  private function fakeClient(): QboClientInterface {
    return new class implements QboClientInterface {

      public function getCustomer(string $qboId): ?array {
        return NULL;
      }

      public function listAllCustomers(): \Generator {
        yield ['Id' => '101', 'DisplayName' => 'Smith, Pat', 'Active' => TRUE,
          'Balance' => 0.0, 'BalanceWithJobs' => 620.0, 'ParentRef' => NULL,
          'PrimaryEmailAddr' => 'pat@example.org'];
        yield ['Id' => '102', 'DisplayName' => 'Smith, Sam', 'Active' => TRUE,
          'Balance' => 620.0, 'BalanceWithJobs' => 620.0, 'ParentRef' => '101',
          'PrimaryEmailAddr' => NULL];
      }

      public function getOpenInvoices(array $qboCustomerIds): array {
        return [];
      }

    };
  }

  public function testRefreshPopulatesMirrorAndHistory(): void {
    $mirror = new Mirror($this->fakeClient());
    $count = $mirror->refresh();
    $this->assertSame(2, $count);

    $row = \CRM_Core_DAO::executeQuery(
      'SELECT * FROM boosterportal_qbo_customer WHERE qbo_id = %1',
      [1 => ['102', 'String']])->fetchAll()[0];
    $this->assertSame('101', $row['parent_ref']);
    $this->assertEquals(620.0, (float) $row['balance']);

    // Refresh again: mirror stays at 2 rows (replace), history accumulates (diffable).
    $mirror->refresh();
    $mirrorCount = (int) \CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM boosterportal_qbo_customer');
    $historyCount = (int) \CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM boosterportal_qbo_balance_history');
    $this->assertSame(2, $mirrorCount);
    $this->assertSame(4, $historyCount);
  }

  public function testRefreshNeverTouchesContacts(): void {
    $before = (int) \CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_contact');
    (new Mirror($this->fakeClient()))->refresh();
    $after = (int) \CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_contact');
    $this->assertSame($before, $after, 'Mirror wrote to contacts — forbidden (§5.4)');
  }

}
