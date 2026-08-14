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

  /**
   * @param float $customer102Balance lets tests vary a re-run's data (e.g.
   *   to prove an upsert takes the LATEST value, not just that row-count
   *   stayed the same) without duplicating the whole fixture.
   */
  private function fakeClient(float $customer102Balance = 620.0): QboClientInterface {
    return new class($customer102Balance) implements QboClientInterface {

      public function __construct(private float $customer102Balance) {
      }

      public function getCustomer(string $qboId): ?array {
        return NULL;
      }

      public function listAllCustomers(): \Generator {
        yield ['Id' => '101', 'DisplayName' => 'Smith, Pat', 'Active' => TRUE,
          'Balance' => 0.0, 'BalanceWithJobs' => 620.0, 'ParentRef' => NULL,
          'PrimaryEmailAddr' => 'pat@example.org'];
        yield ['Id' => '102', 'DisplayName' => 'Smith, Sam', 'Active' => TRUE,
          'Balance' => $this->customer102Balance, 'BalanceWithJobs' => $this->customer102Balance,
          'ParentRef' => '101', 'PrimaryEmailAddr' => NULL];
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

    // Refresh again the SAME day, with a CHANGED balance: mirror stays at 2
    // rows (replace, as before). History is a PER-DAY snapshot (§5.4) — a
    // same-day re-run upserts that day's row (UNIQUE KEY uq_day + ON
    // DUPLICATE KEY UPDATE in Mirror::insertHistory()) rather than
    // appending a second row, and the stored value is the LATEST refresh's
    // value. Varying the balance here (620.0 -> 700.0) proves the upsert
    // actually took the new value, not just that the row count held at 2 —
    // a same-day re-run that changed nothing would look identical either way.
    (new Mirror($this->fakeClient(700.0)))->refresh();
    $mirrorCount = (int) \CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM boosterportal_qbo_customer');
    $historyCount = (int) \CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM boosterportal_qbo_balance_history');
    $this->assertSame(2, $mirrorCount);
    $this->assertSame(2, $historyCount);

    $historyBalance = (float) \CRM_Core_DAO::singleValueQuery(
      'SELECT balance FROM boosterportal_qbo_balance_history WHERE qbo_id = %1',
      [1 => ['102', 'String']]
    );
    $this->assertSame(700.0, $historyBalance);
  }

  public function testRefreshNeverTouchesContacts(): void {
    $before = (int) \CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_contact');
    (new Mirror($this->fakeClient()))->refresh();
    $after = (int) \CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_contact');
    $this->assertSame($before, $after, 'Mirror wrote to contacts — forbidden (§5.4)');
  }

}
