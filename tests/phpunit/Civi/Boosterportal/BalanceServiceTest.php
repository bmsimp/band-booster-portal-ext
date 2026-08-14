<?php
namespace Civi\Boosterportal;

use PHPUnit\Framework\TestCase;

class BalanceServiceTest extends TestCase {

  private function clientReturning(array $customersById, array $invoices = []): QboClientInterface {
    return new class($customersById, $invoices) implements QboClientInterface {

      public function __construct(private array $byId, private array $invoices) {
      }

      public function getCustomer(string $qboId): ?array {
        return $this->byId[$qboId] ?? NULL;
      }

      public function listAllCustomers(): \Generator {
        yield from array_values($this->byId);
      }

      public function getOpenInvoices(array $qboCustomerIds): array {
        return $this->invoices;
      }

    };
  }

  private function cust(string $id, float $balance, ?float $bwj = NULL, ?string $parent = NULL): array {
    return ['Id' => $id, 'DisplayName' => 'C' . $id, 'Active' => TRUE,
      'Balance' => $balance, 'BalanceWithJobs' => $bwj, 'ParentRef' => $parent,
      'PrimaryEmailAddr' => NULL];
  }

  public function testAgreementSumsStudentBalances(): void {
    $svc = new BalanceService($this->clientReturning([
      '101' => $this->cust('101', 0.0, 620.0),
      '102' => $this->cust('102', 400.0, NULL, '101'),
      '103' => $this->cust('103', 220.0, NULL, '101'),
    ]));
    $r = $svc->familyBalance('101', ['102', '103']);
    $this->assertSame(620.0, $r['balance']);
    $this->assertFalse($r['flagged']);
  }

  public function testDisagreementShowsHigherFigureAndFlags(): void {
    // BalanceWithJobs (700) disagrees with student sum (620): show 700, flag.
    $svc = new BalanceService($this->clientReturning([
      '101' => $this->cust('101', 0.0, 700.0),
      '102' => $this->cust('102', 620.0, NULL, '101'),
    ]));
    $r = $svc->familyBalance('101', ['102']);
    $this->assertSame(700.0, $r['balance'], 'Must show the HIGHER figure (§4.6)');
    $this->assertTrue($r['flagged']);
  }

  public function testStudentSumHigherAlsoShowsHigherAndFlags(): void {
    $svc = new BalanceService($this->clientReturning([
      '101' => $this->cust('101', 0.0, 500.0),
      '102' => $this->cust('102', 620.0, NULL, '101'),
    ]));
    $r = $svc->familyBalance('101', ['102']);
    $this->assertSame(620.0, $r['balance']);
    $this->assertTrue($r['flagged']);
  }

  public function testMissingBalanceWithJobsSkipsCrossCheckButFlags(): void {
    // §3.1 non-default trap: absent BalanceWithJobs must NOT read as 0.00
    // (which would force "higher figure" games against a phantom zero).
    $svc = new BalanceService($this->clientReturning([
      '101' => $this->cust('101', 0.0, NULL),
      '102' => $this->cust('102', 620.0, NULL, '101'),
    ]));
    $r = $svc->familyBalance('101', ['102']);
    $this->assertSame(620.0, $r['balance']);
    $this->assertTrue($r['flagged'], 'Missing BalanceWithJobs is itself a reconciliation finding');
  }

  public function testParentOwnBalanceIsNeverTheAnswer(): void {
    // Parent's own Balance says 50; students owe 620. The 50 must not appear.
    $svc = new BalanceService($this->clientReturning([
      '101' => $this->cust('101', 50.0, 670.0),
      '102' => $this->cust('102', 620.0, NULL, '101'),
    ]));
    $r = $svc->familyBalance('101', ['102']);
    $this->assertNotEquals(50.0, $r['balance'], 'Parent Customer.Balance must never be displayed (§4.6)');
    $this->assertSame(670.0, $r['balance'], 'Cross-check basis is students + parent-own vs BalanceWithJobs; higher wins');
  }

}
