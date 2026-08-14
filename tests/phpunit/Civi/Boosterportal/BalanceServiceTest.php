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

  /**
   * A fake QboClientInterface that (a) records every id array passed to
   * getOpenInvoices() so tests can assert exactly what was requested, (b)
   * filters returned invoices to only those keyed under a requested id
   * (mirrors real QBO — you only get invoices back for what you asked for),
   * and (c) can be told a set of "forbidden" ids: calling getCustomer() with
   * one of those throws immediately, proving a call never happened rather
   * than merely that its result went unused.
   */
  private function spyClient(array $customersById, array $invoicesById, array $forbiddenCustomerIds = []): object {
    return new class($customersById, $invoicesById, $forbiddenCustomerIds) implements QboClientInterface {
      public array $requestedInvoiceIds = [];

      public function __construct(private array $byId, private array $invoicesById, private array $forbidden) {
      }

      public function getCustomer(string $qboId): ?array {
        if (in_array($qboId, $this->forbidden, TRUE)) {
          throw new \RuntimeException("getCustomer() must never be called for forbidden id '{$qboId}'");
        }
        return $this->byId[$qboId] ?? NULL;
      }

      public function listAllCustomers(): \Generator {
        yield from array_values($this->byId);
      }

      public function getOpenInvoices(array $qboCustomerIds): array {
        $this->requestedInvoiceIds = $qboCustomerIds;
        $out = [];
        foreach ($qboCustomerIds as $id) {
          foreach ($this->invoicesById[$id] ?? [] as $inv) {
            $out[] = $inv;
          }
        }
        return $out;
      }

    };
  }

  private function cust(string $id, float $balance, ?float $bwj = NULL, ?string $parent = NULL): array {
    return ['Id' => $id, 'DisplayName' => 'C' . $id, 'Active' => TRUE,
      'Balance' => $balance, 'BalanceWithJobs' => $bwj, 'ParentRef' => $parent,
      'PrimaryEmailAddr' => NULL];
  }

  private function invoice(string $customerRef, float $balance): array {
    return ['InvoiceId' => 'INV-' . $customerRef, 'CustomerRef' => $customerRef,
      'DocNumber' => 'D' . $customerRef, 'Balance' => $balance, 'DueDate' => NULL, 'InvoiceLink' => NULL];
  }

  public function testAgreementSumsStudentBalances(): void {
    $svc = new BalanceService($this->clientReturning([
      '101' => $this->cust('101', 0.0, 620.0),
      '102' => $this->cust('102', 400.0, NULL, '101'),
      '103' => $this->cust('103', 220.0, NULL, '101'),
    ]));
    $r = $svc->familyBalance('101', ['102', '103'], TRUE);
    $this->assertSame(620.0, $r['balance']);
    $this->assertFalse($r['flagged']);
  }

  public function testDisagreementShowsHigherFigureAndFlags(): void {
    // BalanceWithJobs (700) disagrees with student sum (620): show 700, flag.
    $svc = new BalanceService($this->clientReturning([
      '101' => $this->cust('101', 0.0, 700.0),
      '102' => $this->cust('102', 620.0, NULL, '101'),
    ]));
    $r = $svc->familyBalance('101', ['102'], TRUE);
    $this->assertSame(700.0, $r['balance'], 'Must show the HIGHER figure (§4.6)');
    $this->assertTrue($r['flagged']);
  }

  public function testStudentSumHigherAlsoShowsHigherAndFlags(): void {
    $svc = new BalanceService($this->clientReturning([
      '101' => $this->cust('101', 0.0, 500.0),
      '102' => $this->cust('102', 620.0, NULL, '101'),
    ]));
    $r = $svc->familyBalance('101', ['102'], TRUE);
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
    $r = $svc->familyBalance('101', ['102'], TRUE);
    $this->assertSame(620.0, $r['balance']);
    $this->assertTrue($r['flagged'], 'Missing BalanceWithJobs is itself a reconciliation finding');
  }

  public function testParentOwnBalanceIsNeverTheAnswer(): void {
    // Parent's own Balance says 50; students owe 620. The 50 must not appear.
    $svc = new BalanceService($this->clientReturning([
      '101' => $this->cust('101', 50.0, 670.0),
      '102' => $this->cust('102', 620.0, NULL, '101'),
    ]));
    $r = $svc->familyBalance('101', ['102'], TRUE);
    $this->assertNotEquals(50.0, $r['balance'], 'Parent Customer.Balance must never be displayed (§4.6)');
    $this->assertSame(670.0, $r['balance'], 'Cross-check basis is students + parent-own vs BalanceWithJobs; higher wins');
  }

  // --- C1/C2: the complete-household gate ---

  public function testIncompleteHouseholdNeverCrossChecksAndFlagsWithReason(): void {
    // BalanceWithJobs (900) is deliberately WRONG/high — if the cross-check
    // ran anyway, "higher figure" logic would surface 900, not 620. It must
    // not run at all when the household is incomplete (a strict subset of
    // its students is visible to this parent).
    $svc = new BalanceService($this->clientReturning([
      '101' => $this->cust('101', 50.0, 900.0),
      '102' => $this->cust('102', 620.0, NULL, '101'),
    ]));
    $r = $svc->familyBalance('101', ['102'], FALSE);
    $this->assertSame(620.0, $r['balance'], 'Incomplete household must show only the verified student(s)\' own Balance sum');
    $this->assertTrue($r['flagged']);
    $this->assertStringContainsString('partial household view', $r['detail']['reason'] ?? '',
      'Incomplete-household flag must carry the specific reason a reconciliation reader needs');
  }

  public function testIncompleteHouseholdNeverFetchesHouseholdCustomerAtAll(): void {
    // Structural proof, not just an unused-value check: getCustomer('101')
    // THROWS if called. If familyBalance() ever fetched the household
    // Customer on the incomplete path — even just to read Balance, let alone
    // BalanceWithJobs — this test errors instead of passing.
    $client = new class implements QboClientInterface {

      public function getCustomer(string $qboId): ?array {
        if ($qboId === '101') {
          throw new \RuntimeException('household customer must never be fetched when the household is incomplete');
        }
        return ['Id' => $qboId, 'DisplayName' => 'C' . $qboId, 'Active' => TRUE,
          'Balance' => 620.0, 'BalanceWithJobs' => NULL, 'ParentRef' => '101', 'PrimaryEmailAddr' => NULL];
      }

      public function listAllCustomers(): \Generator {
        yield from [];
      }

      public function getOpenInvoices(array $qboCustomerIds): array {
        return [];
      }

    };
    $svc = new BalanceService($client);
    $r = $svc->familyBalance('101', ['102'], FALSE);
    $this->assertSame(620.0, $r['balance']);
    $this->assertTrue($r['flagged']);
  }

  // --- I2/M3: multi-household assembly + invoice scoping (BalanceService::balanceForHouseholds) ---

  public function testBalanceForHouseholdsIncompleteHouseholdScopesInvoicesToVerifiedStudentOnly(): void {
    // I4 fixture 1's shape: one household, two students, only one visible to
    // this parent. '101' (the household) is FORBIDDEN — getCustomer() must
    // never be called for it on the incomplete path. '103' is the OTHER,
    // un-verified student in the same household: an invoice sitting under it
    // must never surface, and it must never even be requested.
    $client = $this->spyClient(
      [
        '101' => $this->cust('101', 50.0, 900.0),
        '102' => $this->cust('102', 620.0, NULL, '101'),
      ],
      [
        '102' => [$this->invoice('102', 620.0)],
        '101' => [$this->invoice('101', 50.0)],
        '103' => [$this->invoice('103', 40.0)],
      ],
      forbiddenCustomerIds: ['101']
    );
    $svc = new BalanceService($client);
    $result = $svc->balanceForHouseholds([
      ['qbo_customer_id' => '101', 'verified_student_qbo_ids' => ['102'], 'complete' => FALSE],
    ]);

    $this->assertSame(620.0, $result['balance'], 'Household aggregate must never appear — only the visible student\'s own Balance');
    $this->assertTrue($result['flagged']);
    $this->assertSame(['102'], $client->requestedInvoiceIds,
      'Invoice request must be limited to the verified student id — household qbo id excluded (incomplete), sibling student 103 excluded');
    foreach ($result['invoices'] as $inv) {
      $this->assertNotSame('101', $inv['CustomerRef'], 'No invoice with a CustomerRef outside the verified set');
      $this->assertNotSame('103', $inv['CustomerRef'], 'No invoice with a CustomerRef outside the verified set');
    }
  }

  public function testBalanceForHouseholdsTwoCompleteHouseholdsSumIndependentlyWithNoPermanentFlag(): void {
    // I4 fixture 3's shape: a parent legitimately linked to two DIFFERENT,
    // fully-visible households (e.g. two students who happen to live in
    // separate billing households). Each is complete on its own, each
    // agrees, so the aggregate must NOT carry a permanent flag.
    $client = $this->spyClient(
      [
        '201' => $this->cust('201', 0.0, 300.0),
        '202' => $this->cust('202', 300.0, NULL, '201'),
        '301' => $this->cust('301', 0.0, 150.0),
        '302' => $this->cust('302', 150.0, NULL, '301'),
      ],
      [
        '202' => [$this->invoice('202', 300.0)],
        '302' => [$this->invoice('302', 150.0)],
      ]
    );
    $svc = new BalanceService($client);
    $result = $svc->balanceForHouseholds([
      ['qbo_customer_id' => '201', 'verified_student_qbo_ids' => ['202'], 'complete' => TRUE],
      ['qbo_customer_id' => '301', 'verified_student_qbo_ids' => ['302'], 'complete' => TRUE],
    ]);

    $this->assertSame(450.0, $result['balance']);
    $this->assertFalse($result['flagged'], 'Two legitimately complete, agreeing households must never carry a permanent flag');
    $requested = $client->requestedInvoiceIds;
    sort($requested);
    $this->assertSame(['201', '202', '301', '302'], $requested,
      'Both household qbo ids ARE included for invoice lookup once each household independently passes the complete-set gate');
  }

}
