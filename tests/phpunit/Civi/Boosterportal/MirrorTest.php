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
   * Belt, on top of TransactionalInterface's per-test rollback: start every
   * test with the three boosterportal_* tables confirmed empty, so an
   * assertion here is never accidentally satisfied by a row some earlier
   * test (or a stale --reset seed) happened to leave behind.
   *
   * DELETE, not TRUNCATE: CiviTestListener::startTest() opens the wrapping
   * CRM_Core_Transaction BEFORE this setUp() runs (it's a PHPUnit
   * TestListener::startTest hook, which fires ahead of TestCase::setUp()),
   * so this already executes inside that transaction. TRUNCATE is DDL and
   * triggers MySQL's implicit COMMIT even mid-transaction, which would
   * silently end the wrapping transaction early and defeat rollback
   * isolation for the rest of the test. DELETE participates in the
   * transaction normally and rolls back with everything else.
   */
  protected function setUp(): void {
    parent::setUp();
    \CRM_Core_DAO::executeQuery('DELETE FROM boosterportal_qbo_customer');
    \CRM_Core_DAO::executeQuery('DELETE FROM boosterportal_qbo_balance_history');
    \CRM_Core_DAO::executeQuery('DELETE FROM boosterportal_login_token');
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

  /**
   * BalanceWithJobs ABSENT (not just NULL) is the real-world shape:
   * QboClient::normalizeCustomer() only sets the 'BalanceWithJobs' key when
   * the QBO API response actually included it (array_key_exists check), so
   * a customer with no jobs balance arrives here with the key missing
   * entirely, not present-and-null. Mirror::insertCustomer()'s docblock
   * exists specifically to explain why this can't be handled by binding ''
   * as a Float placeholder; this test is the coverage that path never had.
   */
  public function testRefreshStoresNullBalanceWithJobsWhenAbsent(): void {
    $client = new class implements QboClientInterface {

      public function getCustomer(string $qboId): ?array {
        return NULL;
      }

      public function listAllCustomers(): \Generator {
        yield ['Id' => '301', 'DisplayName' => 'No Jobs Balance', 'Active' => TRUE,
          'Balance' => 50.0, 'ParentRef' => NULL, 'PrimaryEmailAddr' => NULL];
      }

      public function getOpenInvoices(array $qboCustomerIds): array {
        return [];
      }

    };

    (new Mirror($client))->refresh();

    $mirrorIsNull = (bool) \CRM_Core_DAO::singleValueQuery(
      'SELECT balance_with_jobs IS NULL FROM boosterportal_qbo_customer WHERE qbo_id = %1',
      [1 => ['301', 'String']]
    );
    $this->assertTrue($mirrorIsNull, 'balance_with_jobs should be SQL NULL, not 0 or \'\', when BalanceWithJobs is absent');

    $historyIsNull = (bool) \CRM_Core_DAO::singleValueQuery(
      'SELECT balance_with_jobs IS NULL FROM boosterportal_qbo_balance_history WHERE qbo_id = %1',
      [1 => ['301', 'String']]
    );
    $this->assertTrue($historyIsNull, 'history.balance_with_jobs should also be SQL NULL when absent');
  }

  public function testRefreshNeverTouchesContacts(): void {
    $before = (int) \CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_contact');
    (new Mirror($this->fakeClient()))->refresh();
    $after = (int) \CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_contact');
    $this->assertSame($before, $after, 'Mirror wrote to contacts — forbidden (§5.4)');
  }

}
