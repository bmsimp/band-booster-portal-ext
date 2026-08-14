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
 * empty state, the credential gate, and turning any failure below that gate
 * into one fixed, opaque message before it ever reaches a parent (I3,
 * widened by R4 — see failOpaque() below — to cover FamilyResolver
 * derivation failures too, not only the QBO call).
 *
 * R2 (second security review): 'flagged' and 'partial' are deliberately
 * separate signals in the payload — see BalanceService's docblock for why.
 * A by-design partial view logs at INFO (informational, not actionable); an
 * actual cross-check mismatch logs at WARNING (the treasurer's signal).
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

    // R4 (second security review): derivation — students, billing
    // household(s), and each household's complete-set gate inputs — is all
    // local CiviCRM/DB work, no QBO involved, but it can still throw (a DB
    // error, an ACL-evaluation exception, FamilyResolver's own defensive
    // guard, ...). Previously only the QBO block below was wrapped; a
    // derivation failure would have surfaced whatever raw exception text
    // CiviCRM/the DB produced directly to the parent. Same containment as
    // the QBO block: caught, logged with full detail server-side, rethrown
    // opaque.
    try {
      // Students this parent may VIEW, then the billing household(s) those
      // students belong to — both ACL-scoped to $cid, never to anything a
      // caller could influence. See FamilyResolver for how (and why it
      // isn't the plan's literal join).
      $students = FamilyResolver::studentsOf($cid);
      $households = FamilyResolver::billingHouseholdsOf($students);

      // C1/C2 gate inputs, one household at a time: is this parent's
      // verified student set (from $households, already ACL-derived) THE
      // household's complete active student set? activeStudentCountOf() is
      // a local, count-only DB query — still no QBO touched.
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
    }
    catch (\Throwable $e) {
      $this->failOpaque($e, $cid);
    }

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
    // ONE message (like "Not logged in." above) is deliberately exempt from
    // the opaque-error rule: it names no QBO query/customer/invoice detail,
    // just "go read the runbook" — there is nothing in it a parent
    // shouldn't see.
    $clientId = \Civi::settings()->get('boosterportal_qbo_client_id');
    $refreshToken = \Civi::settings()->get('boosterportal_qbo_refresh_token');
    if (empty($clientId) || empty($refreshToken)) {
      throw new \CRM_Core_Exception('QuickBooks is not connected — see runbooks/qbo-oauth.md.');
    }

    try {
      $client = new QboClient();
      $assembled = (new BalanceService($client))->balanceForHouseholds($householdInputs);
    }
    catch (\Throwable $e) {
      $this->failOpaque($e, $cid);
    }

    foreach ($assembled['perHousehold'] as $hh) {
      // R2: a genuine cross-check mismatch (flagged) is the treasurer's
      // signal — WARNING. A by-design partial view (partial) is expected
      // and non-actionable on its own — INFO, and never both at once (see
      // BalanceService — the two fields are mutually exclusive per
      // household).
      if ($hh['flagged']) {
        \Civi::log()->warning('boosterportal balance cross-check mismatch', [
          'contact_id' => $cid,
          'household_qbo_id' => $hh['qbo_customer_id'],
          'complete' => $hh['complete'],
          'detail' => $hh['detail'] ?? NULL,
        ]);
      }
      elseif ($hh['partial']) {
        \Civi::log()->info('boosterportal balance partial household view', [
          'contact_id' => $cid,
          'household_qbo_id' => $hh['qbo_customer_id'],
          'detail' => $hh['detail'] ?? NULL,
        ]);
      }
    }

    // MINOR-7 (quality review, Task 17): 'students' is NOT read by
    // js/booster-balance.js today — the "Your students" SearchDisplay
    // (Task 17) already covers that on the dashboard page, so the widget
    // has no need to duplicate it. Kept in the payload anyway rather than
    // removed: Task 12's GetMyBalanceTest asserts against this exact field
    // as its family-A/family-B leak-detection signal (a sentinel display
    // name must never appear in the OTHER family's response) — removing it
    // would require reworking that regression test's signal, which is out
    // of scope for a widget change. Fine to drop later if that test is ever
    // rewritten to use a different signal and no UI consumer has appeared.
    $payload = [
      'balance' => $assembled['balance'],
      'flagged' => $assembled['flagged'],
      'partial' => $assembled['partial'],
      'students' => array_values(array_column($students, 'display_name')),
      'invoices' => $assembled['invoices'],
      'empty' => FALSE,
    ];
    \Civi::cache('short')->set($cacheKey, $payload, 60);
    $result[] = $payload;
  }

  /**
   * I3, widened by R4: every failure below the credential gate — QBO down,
   * an expired/revoked refresh token, a genuine SDK bug, a FamilyResolver
   * derivation error, anything — is logged here with full detail
   * server-side (contact id, message, trace) and replaced with ONE fixed,
   * opaque message. A parent must never see QBO query text, customer/
   * invoice ids, SQL, or anything else that could hint at internals or
   * another family's data. Always throws; never returns.
   */
  private function failOpaque(\Throwable $e, int $cid): void {
    \Civi::log()->warning('boosterportal getMyBalance failed', [
      'contact_id' => $cid,
      'error' => $e->getMessage(),
      'trace' => $e->getTraceAsString(),
    ]);
    throw new \CRM_Core_Exception('Balance lookup failed — please try again shortly.');
  }

  private static function emptyPayload(): array {
    return ['balance' => NULL, 'flagged' => FALSE, 'partial' => FALSE, 'invoices' => [], 'students' => [], 'empty' => TRUE];
  }

}
