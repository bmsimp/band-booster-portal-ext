<?php
namespace Civi\Boosterportal;

use QuickBooksOnline\API\DataService\DataService;

class QboClient implements QboClientInterface {

  private ?DataService $ds = NULL;

  private function ds(): DataService {
    if ($this->ds === NULL) {
      $this->ds = DataService::Configure([
        'auth_mode' => 'oauth2',
        'ClientID' => \Civi::settings()->get('boosterportal_qbo_client_id'),
        'ClientSecret' => \Civi::settings()->get('boosterportal_qbo_client_secret'),
        'RefreshToken' => \Civi::settings()->get('boosterportal_qbo_refresh_token'),
        'QBORealmID' => \Civi::settings()->get('boosterportal_qbo_realm_id'),
        'baseUrl' => \Civi::settings()->get('boosterportal_qbo_env') === 'production'
          ? 'Production' : 'Development',
      ]);
      $this->ds->setMinorVersion(75); // invoiceLink needs >= 36 (§2)
      $this->refreshAccessToken();
    }
    return $this->ds;
  }

  private function refreshAccessToken(): void {
    $helper = $this->ds->getOAuth2LoginHelper();
    $token = $helper->refreshToken();
    $this->ds->updateOAuth2Token($token);
    // Intuit ROTATES refresh tokens; persist the new one or the connection
    // dies within 100 days (runbooks/qbo-oauth.md).
    \Civi::settings()->set('boosterportal_qbo_refresh_token', $token->getRefreshToken());
  }

  public static function normalizeCustomer(array $raw): array {
    return [
      'Id' => (string) $raw['Id'],
      'DisplayName' => $raw['DisplayName'] ?? '',
      'Active' => (bool) ($raw['Active'] ?? FALSE),
      'Balance' => (float) ($raw['Balance'] ?? 0),
      'BalanceWithJobs' => array_key_exists('BalanceWithJobs', $raw)
        ? (float) $raw['BalanceWithJobs'] : NULL,
      'ParentRef' => isset($raw['ParentRef']) ? (string) ($raw['ParentRef']['value'] ?? $raw['ParentRef']) : NULL,
      'PrimaryEmailAddr' => $raw['PrimaryEmailAddr']['Address'] ?? NULL,
    ];
  }

  public function getCustomer(string $qboId): ?array {
    // $qboId is always a QBO numeric id sourced from our own DB (never raw
    // end-user input), so addslashes() is adequate escaping for the single
    // quotes QBO's query language uses; see the same note on getOpenInvoices().
    $rows = $this->query(sprintf(
      "SELECT * FROM Customer WHERE Id = '%s'", addslashes($qboId)));
    return $rows ? static::normalizeCustomer($rows[0]) : NULL;
  }

  public function listAllCustomers(): \Generator {
    $start = 1;
    do {
      $rows = $this->query(
        "SELECT * FROM Customer WHERE Active IN (true, false) STARTPOSITION {$start} MAXRESULTS 100");
      foreach ($rows as $row) {
        yield static::normalizeCustomer($row);
      }
      $start += 100;
    } while (count($rows) === 100);
  }

  public function getOpenInvoices(array $qboCustomerIds): array {
    if (!$qboCustomerIds) {
      return [];
    }
    // QBO's SQL-like query language escapes a literal single quote by
    // doubling it or backslash-escaping it; addslashes() backslash-escapes
    // quotes and backslashes, which QBO accepts. This is safe here only
    // because $qboCustomerIds always come from our own civicrm_* mirror
    // tables (QBO numeric ids we previously stored), never directly from
    // end-user input — addslashes() is not a general-purpose QBO query
    // sanitizer and must not be relied on for arbitrary strings.
    $in = implode("','", array_map('addslashes', $qboCustomerIds));
    $rows = $this->query(
      "SELECT * FROM Invoice WHERE Balance > '0' AND CustomerRef IN ('{$in}')");
    $out = [];
    foreach ($rows as $row) {
      $out[] = [
        'InvoiceId' => (string) $row['Id'],
        'CustomerRef' => (string) ($row['CustomerRef']['value'] ?? $row['CustomerRef']),
        'DocNumber' => (string) ($row['DocNumber'] ?? ''),
        'Balance' => (float) $row['Balance'],
        'DueDate' => $row['DueDate'] ?? NULL,
        'InvoiceLink' => $row['InvoiceLink'] ?? NULL,
      ];
    }
    return $out;
  }

  /** @return array[] decoded entity rows */
  protected function query(string $sql): array {
    $entities = $this->ds()->Query($sql) ?: [];
    if ($err = $this->ds()->getLastError()) {
      throw new \CRM_Core_Exception('QBO query failed: ' . $err->getResponseBody());
    }
    // SDK returns IPPIntuitEntity objects; cast via JSON round-trip so
    // normalizeCustomer() deals in plain arrays (and unit tests need no SDK).
    return json_decode(json_encode($entities), TRUE) ?: [];
  }

}
