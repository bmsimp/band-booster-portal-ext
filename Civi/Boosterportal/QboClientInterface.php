<?php
namespace Civi\Boosterportal;

/**
 * The ONLY seam to QuickBooks Online. Nothing else in the extension may
 * construct an Intuit SDK object or issue HTTP to Intuit (§5.4, file structure).
 */
interface QboClientInterface {

  /**
   * @return array{Id: string, Balance: float, BalanceWithJobs: ?float, Active: bool,
   *   DisplayName: string, ParentRef: ?string, PrimaryEmailAddr: ?string}|null
   *   BalanceWithJobs is nullable on purpose: it is a non-default field (§3.1)
   *   and its absence must be visible, not coerced to 0.00.
   */
  public function getCustomer(string $qboId): ?array;

  /**
   * All customers and sub-customers, normalized to the getCustomer() shape.
   * Pages through the QBO query API; includes inactive records.
   * @return \Generator<array>
   */
  public function listAllCustomers(): \Generator;

  /**
   * Open invoices for the given customer ids, each with its hosted-payment link.
   * @return array<array{InvoiceId: string, CustomerRef: string, DocNumber: string,
   *   Balance: float, DueDate: ?string, InvoiceLink: ?string}>
   */
  public function getOpenInvoices(array $qboCustomerIds): array;

}
