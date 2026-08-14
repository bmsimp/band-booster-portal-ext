<?php
namespace Civi\Boosterportal;

use PHPUnit\Framework\TestCase;

class QboClientTest extends TestCase {

  public function testNormalizeCustomerKeepsMissingBalanceWithJobsNull(): void {
    $raw = [
      'Id' => '101', 'DisplayName' => 'Smith, Pat', 'Active' => TRUE,
      'Balance' => 0.0,
      // BalanceWithJobs deliberately absent — the §3.1 non-default trap.
    ];
    $n = QboClient::normalizeCustomer($raw);
    $this->assertNull($n['BalanceWithJobs'], 'Absent BalanceWithJobs must be NULL, never 0.00 (§3.1)');
    $this->assertSame(0.0, $n['Balance']);
    $this->assertNull($n['ParentRef']);
  }

  public function testNormalizeCustomerFlattensRefsAndEmail(): void {
    $raw = [
      'Id' => '102', 'DisplayName' => 'Smith, Sam', 'Active' => TRUE,
      'Balance' => 620.0, 'BalanceWithJobs' => 620.0,
      'ParentRef' => ['value' => '101'],
      'PrimaryEmailAddr' => ['Address' => 'pat@example.org'],
    ];
    $n = QboClient::normalizeCustomer($raw);
    $this->assertSame('101', $n['ParentRef']);
    $this->assertSame('pat@example.org', $n['PrimaryEmailAddr']);
    $this->assertSame(620.0, $n['BalanceWithJobs']);
  }

}
