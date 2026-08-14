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
 * regardless of what a client sends. Cached 60s per contact — INCLUDING the
 * explicit empty state (M1, post-Task-12 adversarial review): a parent with
 * nothing to see still gets a cheap cache hit on repeat calls rather than
 * re-deriving every time.
 *
 * Derivation (which students, which billing household(s) — a parent can
 * legitimately have verified students in more than one household, I2) lives
 * in Civi\Boosterportal\FamilyResolver. The §4.6 maths, the per-household
 * complete-set gate (C1/C2 — BalanceWithJobs is a household-level aggregate
 * and is only readable when this parent's verified students ARE that
 * household's complete active student set), and invoice scoping (M3) all
 * live in Civi\Boosterportal\BalanceService::balanceForHouseholds() — see
 * both classes' docblocks. This action stays thin: session, cache, the
 * empty state, the credential gate, and (I3) turning any QBO-layer failure
 * into one fixed, opaque message before it ever reaches a parent.
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

    // Students this parent may VIEW, then the billing household(s) those
    // students belong to — both ACL-scoped to $cid, never to anything a
    // caller could influence. See FamilyResolver for how (and why it isn't
    // the plan's literal join).
    $students = FamilyResolver::studentsOf($cid);
    $households = FamilyResolver::billingHouseholdsOf($students);

    if (!$students || !$households) {
      // No students with qbo ids, or no billing household with a qbo id
      // (recon check #9 territory — a parent with nothing to see): an
      // explicit empty state, never a zero that could read as "paid up".
      // M1: cached the same as any other result — a parent with nothing to
      // see shouldn't re-derive on every page load either.
      $payload = self::emptyPayload();
      \Civi::cache('short')->set($cacheKey, $payload, 60);
      $result[] = $payload;
      return;
    }

    // Nothing above touches QBO. From here on it does, so verify the
    // connection exists first — same precise, cron-proven message
    // RefreshMirror uses (Task 9/10), rather than relying on whatever
    // exception text the SDK happens to throw for "never connected". This
    // ONE message is deliberately exempt from I3's opaque-error rule below:
    // it names no QBO query/customer/invoice detail, just "go read the
    // runbook" — there is nothing in it a parent shouldn't see.
    $clientId = \Civi::settings()->get('boosterportal_qbo_client_id');
    $refreshToken = \Civi::settings()->get('boosterportal_qbo_refresh_token');
    if (empty($clientId) || empty($refreshToken)) {
      throw new \CRM_Core_Exception('QuickBooks is not connected — see runbooks/qbo-oauth.md.');
    }

    // C1/C2 gate inputs, one household at a time: is this parent's verified
    // student set (from $households, already ACL-derived) THE household's
    // complete active student set? activeStudentCountOf() is a local,
    // count-only DB query — still no QBO touched.
    $householdInputs = [];
    foreach ($households as $hh) {
      $verifiedCount = count($hh['students']);
      $completeCount = FamilyResolver::activeStudentCountOf($hh['id']);
      $householdInputs[] = [
        'qbo_customer_id' => $hh['qbo_customer_id'],
        'verified_student_qbo_ids' => array_column($hh['students'], 'qbo_subcustomer_id'),
        'complete' => $completeCount > 0 && $verifiedCount === $completeCount,
      ];
    }

    // I3: from here on, every failure — QBO down, an expired/revoked
    // refresh token, a genuine SDK bug, anything — is caught, logged with
    // full detail server-side only, and rethrown as one fixed, opaque
    // message. A parent must never see QBO query text, customer/invoice
    // ids, or anything else that could hint at internals or another
    // family's data.
    try {
      $client = new QboClient();
      $assembled = (new BalanceService($client))->balanceForHouseholds($householdInputs);
    }
    catch (\Throwable $e) {
      \Civi::log()->warning('boosterportal getMyBalance failed', [
        'contact_id' => $cid,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
      ]);
      throw new \CRM_Core_Exception('Balance lookup failed — please try again shortly.');
    }

    foreach ($assembled['perHousehold'] as $hh) {
      if ($hh['flagged']) {
        \Civi::log()->warning('boosterportal balance cross-check mismatch', [
          'contact_id' => $cid,
          'household_qbo_id' => $hh['qbo_customer_id'],
          'complete' => $hh['complete'],
          'detail' => $hh['detail'] ?? NULL,
        ]);
      }
    }

    $payload = [
      'balance' => $assembled['balance'],
      'flagged' => $assembled['flagged'],
      'students' => array_values(array_column($students, 'display_name')),
      'invoices' => $assembled['invoices'],
      'empty' => FALSE,
    ];
    \Civi::cache('short')->set($cacheKey, $payload, 60);
    $result[] = $payload;
  }

  private static function emptyPayload(): array {
    return ['balance' => NULL, 'flagged' => FALSE, 'invoices' => [], 'students' => [], 'empty' => TRUE];
  }

}
