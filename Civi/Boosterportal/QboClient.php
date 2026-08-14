<?php
namespace Civi\Boosterportal;

use QuickBooksOnline\API\DataService\DataService;

class QboClient implements QboClientInterface {

  /**
   * Civi::cache() bucket used to hold the current QBO access token.
   * 'short' is CiviCRM's general-purpose keyed cache (aliased to
   * cache.default / CRM_Utils_Cache::singleton()) and, unlike 'long'
   * (which wraps a FastArrayDecorator specifically for values nobody cares
   * about the TTL of — see core Container.php), it honours an explicit
   * per-item TTL, which matters here: see ACCESS_TOKEN_TTL below.
   */
  private const ACCESS_TOKEN_CACHE_BUCKET = 'short';

  /**
   * QBO access tokens are valid for 3600 seconds. Caching for 3300 (55
   * minutes) leaves a 5-minute margin so this process never tries to use a
   * token QBO's servers already consider expired (clock skew, cache-fetch
   * latency, etc.) — worst case we refresh a few minutes early, which is
   * cheap; using an actually-expired cached token would hard-fail the whole
   * request instead.
   */
  private const ACCESS_TOKEN_TTL = 3300;

  private ?DataService $ds = NULL;

  private function ds(): DataService {
    if ($this->ds === NULL) {
      $cachedAccessToken = \Civi::cache(self::ACCESS_TOKEN_CACHE_BUCKET)->get($this->accessTokenCacheKey());

      $config = [
        'auth_mode' => 'oauth2',
        'ClientID' => \Civi::settings()->get('boosterportal_qbo_client_id'),
        'ClientSecret' => \Civi::settings()->get('boosterportal_qbo_client_secret'),
        'RefreshToken' => \Civi::settings()->get('boosterportal_qbo_refresh_token'),
        'QBORealmID' => \Civi::settings()->get('boosterportal_qbo_realm_id'),
        'baseUrl' => \Civi::settings()->get('boosterportal_qbo_env') === 'production'
          ? 'Production' : 'Development',
      ];
      if ($cachedAccessToken !== NULL) {
        // A still-valid access token is cached: configure the SDK with it
        // directly (the SDK accepts a pre-obtained access token via
        // 'accessTokenKey' — see CoreConstants::getAccessTokenFromArray())
        // so ds() below does NOT call refreshToken(). Skipping that call on
        // a cache hit is the entire point of this cache; see the long
        // comment on refreshAccessToken() for why it matters.
        $config['accessTokenKey'] = $cachedAccessToken;
      }
      $this->ds = DataService::Configure($config);
      $this->ds->setMinorVersion(75); // invoiceLink needs >= 36 (§2)
      if ($cachedAccessToken === NULL) {
        $this->refreshAccessToken();
      }
    }
    return $this->ds;
  }

  /**
   * Scoped by realm id so a reconnect to a *different* QBO company (Task 9's
   * OAuth page, which only ever updates civicrm_setting, not this cache)
   * can never serve a stale access token minted for the previous company —
   * the cache key for the new realm id simply starts out empty and the
   * first request pays for one refreshToken() call, same as a cold cache.
   */
  private function accessTokenCacheKey(): string {
    return 'boosterportal_qbo_access_token:' . \Civi::settings()->get('boosterportal_qbo_realm_id');
  }

  private function refreshAccessToken(): void {
    $helper = $this->ds->getOAuth2LoginHelper();
    $token = $helper->refreshToken();
    $this->ds->updateOAuth2Token($token);
    // Intuit ROTATES refresh tokens; persist the new one or the connection
    // dies within 100 days (runbooks/qbo-oauth.md).
    \Civi::settings()->set('boosterportal_qbo_refresh_token', $token->getRefreshToken());
    // Cache the freshly-minted access token (see ds()) so later QboClient
    // instances — in this process or a later one — skip refreshToken()
    // entirely while it's still fresh. This matters because Intuit rotates
    // the refresh token on every use with NO grace period: the instant a
    // new refresh token is issued, the old one stops working. QboClient is
    // constructed once per parent-facing balance request (Task 12); without
    // this cache, every single page view would call refreshToken() again,
    // and two requests racing to refresh at close to the same moment would
    // leave the loser calling refreshToken() with an already-rotated
    // (dead) refresh token, which Intuit answers with invalid_grant and a
    // hard failure. Caching the access token — never the refresh token,
    // which still only ever lives in civicrm_setting — means only the
    // first request after the cache genuinely expires pays for a refresh,
    // which shrinks the race window from "every request" to "at most once
    // every ACCESS_TOKEN_TTL seconds". No DB locking on top of this: out of
    // scope until the remaining race window is shown to matter in practice.
    \Civi::cache(self::ACCESS_TOKEN_CACHE_BUCKET)->set(
      $this->accessTokenCacheKey(), $token->getAccessToken(), self::ACCESS_TOKEN_TTL);
  }

  public static function normalizeCustomer(array $raw): array {
    return [
      'Id' => (string) $raw['Id'],
      'DisplayName' => $raw['DisplayName'] ?? '',
      // The SDK's XML-backed deserializer hands back STRING leaf values, so
      // a real QBO Customer entity arrives as 'Active' => 'false' (string),
      // never PHP's boolean FALSE. (bool) 'false' === TRUE, so a plain cast
      // would silently mark every inactive customer as active.
      // filter_var(..., FILTER_VALIDATE_BOOLEAN) correctly parses both the
      // string and native-bool/int shapes ("false"/"0"/FALSE -> FALSE,
      // "true"/"1"/TRUE -> TRUE).
      'Active' => filter_var($raw['Active'] ?? FALSE, FILTER_VALIDATE_BOOLEAN),
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
    // The IN clause below is also un-chunked: it assumes this project's
    // scale (a few hundred families, well under ~1000 ids) fits in one
    // query comfortably within QBO's request-size limits.
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
