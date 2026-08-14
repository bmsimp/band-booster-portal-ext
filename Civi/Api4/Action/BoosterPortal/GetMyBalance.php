<?php
namespace Civi\Api4\Action\BoosterPortal;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Boosterportal\BalanceService;
use Civi\Boosterportal\FamilyResolver;
use Civi\Boosterportal\QboClient;

/**
 * Family balance for the LOGGED-IN parent (§3.1, §4.6). Deliberately takes
 * no contact/household parameter: the family is derived entirely from the
 * session contact id, so the action cannot be aimed at someone else's data
 * regardless of what a client sends. Cached 60s per contact.
 *
 * Derivation itself (which students, which billing household) lives in
 * Civi\Boosterportal\FamilyResolver, not here — see that class's docblock
 * for why the plan's literal
 * Contact::get(TRUE)->addJoin('Relationship AS rel', ...) approach was
 * dropped (empirically returns zero rows for a real portal parent) in
 * favour of FamilyResolver's two-layer SQL-then-ACL-verify approach, and
 * for the containment argument behind its one checkPermissions FALSE call
 * (the household lookup), also documented on InvariantTest's allowlist.
 * Keeping that logic out of this file is also what makes it possible to
 * headless-test the derivation+scoping (AclLeakTest) without ever needing a
 * live or fake QBO connection — this action itself stays un-mocked-but-thin
 * (GetMyBalanceTest exercises only its own glue: login check, cache, the
 * empty state, and the credential gate below).
 */
class GetMyBalance extends AbstractAction {

  public function _run(Result $result) {
    $cid = \CRM_Core_Session::getLoggedInContactID();
    if (!$cid) {
      throw new \CRM_Core_Exception('Not logged in.');
    }

    $cacheKey = "boosterportal_balance_{$cid}";
    $cached = \Civi::cache('short')->get($cacheKey);
    if ($cached !== NULL) {
      $result[] = $cached;
      return;
    }

    // Students this parent may VIEW, then the billing household those
    // students belong to — both ACL-scoped to $cid, never to anything a
    // caller could influence. See FamilyResolver for how (and why it isn't
    // the plan's literal join).
    $students = FamilyResolver::studentsOf($cid);
    $studentQboIds = array_column($students, 'qbo_subcustomer_id');
    $studentIds = array_column($students, 'id');
    $household = $studentIds ? FamilyResolver::billingHouseholdOf($studentIds) : NULL;

    if (!$household || !$studentQboIds) {
      // No students with qbo ids, or no billing household with a qbo id
      // (recon check #9 territory — a parent with nothing to see): an
      // explicit empty state, never a zero that could read as "paid up".
      $result[] = ['balance' => NULL, 'flagged' => FALSE, 'invoices' => [],
        'students' => [], 'empty' => TRUE];
      return;
    }

    // Nothing above touches QBO. From here on it does, so verify the
    // connection exists first — same precise, cron-proven message
    // RefreshMirror uses (Task 9/10), rather than relying on whatever
    // exception text the SDK happens to throw for "never connected".
    $clientId = \Civi::settings()->get('boosterportal_qbo_client_id');
    $refreshToken = \Civi::settings()->get('boosterportal_qbo_refresh_token');
    if (empty($clientId) || empty($refreshToken)) {
      throw new \CRM_Core_Exception('QuickBooks is not connected — see runbooks/qbo-oauth.md.');
    }

    // Credentials are present but something else went wrong (QBO down, the
    // refresh token expired/revoked, a genuine SDK bug, ...) — same
    // broad-catch-and-rewrap RefreshMirror uses, so a parent sees a clear
    // message instead of a raw SDK/HTTP fatal.
    try {
      $client = new QboClient();
      $balance = (new BalanceService($client))
        ->familyBalance($household['qbo_customer_id'], $studentQboIds);
      $invoices = $client->getOpenInvoices(array_merge($studentQboIds, [$household['qbo_customer_id']]));
    }
    catch (\Throwable $e) {
      throw new \CRM_Core_Exception('Balance lookup failed: ' . $e->getMessage());
    }

    if ($balance['flagged']) {
      \Civi::log()->warning('boosterportal balance cross-check mismatch', [
        'contact_id' => $cid,
        'detail' => $balance['detail'],
      ]);
    }

    $payload = [
      'balance' => $balance['balance'],
      'flagged' => $balance['flagged'],
      'students' => array_values(array_column($students, 'display_name')),
      'invoices' => $invoices,
      'empty' => FALSE,
    ];
    \Civi::cache('short')->set($cacheKey, $payload, 60);
    $result[] = $payload;
  }

}
