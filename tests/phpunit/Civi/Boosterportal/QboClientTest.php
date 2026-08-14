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

  /**
   * The SDK's XML-backed deserializer produces STRING leaf values, so a raw
   * QBO Customer entity arrives with 'Active' => 'false' (string), not the
   * PHP boolean FALSE the other tests use. (bool) 'false' === TRUE in PHP,
   * so a naive cast silently marks every inactive customer as active. This
   * is the real production shape — reproduced here rather than assumed.
   */
  public function testNormalizeCustomerParsesSdkStringFalseAsInactive(): void {
    $raw = [
      'Id' => '103', 'DisplayName' => 'Jones, Alex', 'Active' => 'false',
      'Balance' => 0.0,
    ];
    $n = QboClient::normalizeCustomer($raw);
    $this->assertFalse($n['Active'], "SDK string 'false' must normalize to boolean FALSE, not TRUE");
  }

  public function testNormalizeCustomerParsesSdkStringTrueAsActive(): void {
    $raw = [
      'Id' => '104', 'DisplayName' => 'Jones, Casey', 'Active' => 'true',
      'Balance' => 0.0,
    ];
    $n = QboClient::normalizeCustomer($raw);
    $this->assertTrue($n['Active'], "SDK string 'true' must normalize to boolean TRUE");
  }

}
