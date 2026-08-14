<?php
namespace Civi\Boosterportal;

/**
 * The ONLY place balance maths lives (§4.6). No dollar figure shown to a
 * parent is ever computed from CiviCRM data (§4.5) — inputs here are live QBO.
 *
 * C1/C2 (adversarial security review, post-Task-12): the §4.6 cross-check
 * (BalanceWithJobs vs student-sum-plus-parent-own, higher figure wins) is
 * only valid when the caller's verified student set for a household IS that
 * household's complete active student set. BalanceWithJobs is a
 * HOUSEHOLD-level aggregate — reading or comparing it for a household where
 * the caller can only see SOME students would let a partial view infer the
 * balance of students the caller cannot see (a split family, a revoked/
 * expired parent-student edge, ...). So familyBalance() takes
 * $completeHousehold as an explicit, required argument: the maths itself
 * enforces the gate, rather than trusting every caller to remember to check
 * first. On the incomplete path, the household Customer is never even
 * fetched (see the method body) — "never read BalanceWithJobs" is
 * structural, not just an unused return value.
 *
 * I2/M3: balanceForHouseholds() is the multi-household entry point
 * (FamilyResolver::billingHouseholdsOf() — a parent can legitimately have
 * verified students across more than one billing household). It applies the
 * gate above independently per household (a parent complete in one
 * household and partial in another gets accurate maths for each) and owns
 * the invoice-scoping rule: verified student qbo ids are ALWAYS included,
 * a household's own qbo id is included ONLY once that household passes the
 * complete-set gate — an incomplete household's own Customer/invoices are
 * never looked up, for the same reason its BalanceWithJobs is never read.
 */
class BalanceService {

  public function __construct(private QboClientInterface $client) {
  }

  /**
   * @param string $householdQboId  the family's parent Customer id
   * @param string[] $studentQboIds the VERIFIED students' sub-customer ids
   *   for this household (never the household's full student list unless
   *   $completeHousehold is also TRUE)
   * @param bool $completeHousehold
   *   TRUE only when $studentQboIds is this household's entire active
   *   student set, not merely the subset the caller can see. Gates the
   *   §4.6 cross-check: FALSE makes reading/comparing BalanceWithJobs
   *   structurally unreachable (see below) rather than merely unused.
   * @return array{balance: float, flagged: bool, detail: array}
   */
  public function familyBalance(string $householdQboId, array $studentQboIds, bool $completeHousehold): array {
    $studentSum = 0.0;
    foreach ($studentQboIds as $sid) {
      $c = $this->client->getCustomer($sid);
      $studentSum += $c ? $c['Balance'] : 0.0;
    }

    if (!$completeHousehold) {
      // C1/C2: this parent cannot see every student in this household, so
      // the household-level BalanceWithJobs figure must never be read or
      // compared — deliberately no call to
      // $this->client->getCustomer($householdQboId) anywhere on this path.
      // The displayed balance is exactly the sum of the students this
      // parent CAN see, nothing else, always flagged so reconciliation
      // (not this parent's screen) is where the full picture gets checked.
      return ['balance' => round($studentSum, 2), 'flagged' => TRUE,
        'detail' => ['student_sum' => $studentSum,
          'reason' => 'partial household view — cross-check deferred to reconciliation']];
    }

    $parent = $this->client->getCustomer($householdQboId);
    $parentOwn = $parent ? $parent['Balance'] : 0.0;
    $bwj = $parent ? $parent['BalanceWithJobs'] : NULL;

    // Cross-check (§4.6 + recon check #6): BalanceWithJobs should equal
    // student sum + the parent's own open balance. The parent's own Balance
    // participates in the CHECK; it is never itself the displayed answer
    // (recon check #7 fires when it is non-zero).
    $expected = $studentSum + $parentOwn;

    if ($bwj === NULL) {
      // §3.1: absent is not zero. Skip the cross-check, surface the sum, flag.
      return ['balance' => round($studentSum, 2), 'flagged' => TRUE,
        'detail' => ['student_sum' => $studentSum, 'balance_with_jobs' => NULL,
          'reason' => 'BalanceWithJobs not returned']];
    }

    $agrees = abs($bwj - $expected) < 0.005;
    $display = $agrees ? $expected : max($bwj, $expected);
    return [
      'balance' => round($display, 2),
      'flagged' => !$agrees,
      'detail' => ['student_sum' => $studentSum, 'parent_own' => $parentOwn,
        'balance_with_jobs' => $bwj],
    ];
  }

  /**
   * Aggregates familyBalance() across every billing household a parent has
   * verified students in (I2), and owns the invoice-scoping rule (M3).
   *
   * @param array<array{qbo_customer_id: string, verified_student_qbo_ids: string[], complete: bool}> $households
   * @return array{balance: float, flagged: bool, invoices: array, perHousehold: array}
   *   'perHousehold' carries each household's own familyBalance() result
   *   (plus its qbo_customer_id/complete flag) for the caller to log
   *   mismatch detail per household without re-deriving anything.
   */
  public function balanceForHouseholds(array $households): array {
    $total = 0.0;
    $flagged = FALSE;
    $verifiedStudentQboIds = [];
    $completeHouseholdQboIds = [];
    $perHousehold = [];

    foreach ($households as $hh) {
      $verifiedStudentQboIds = array_merge($verifiedStudentQboIds, $hh['verified_student_qbo_ids']);
      $r = $this->familyBalance($hh['qbo_customer_id'], $hh['verified_student_qbo_ids'], $hh['complete']);
      $total += $r['balance'];
      if ($r['flagged']) {
        $flagged = TRUE;
      }
      if ($hh['complete']) {
        // M3: a household's own qbo id is only ever looked up (invoices
        // included) once it has independently passed the complete-set gate
        // — same containment reasoning as the BalanceWithJobs read above.
        $completeHouseholdQboIds[] = $hh['qbo_customer_id'];
      }
      $perHousehold[] = ['qbo_customer_id' => $hh['qbo_customer_id'], 'complete' => $hh['complete']] + $r;
    }

    $verifiedStudentQboIds = array_values(array_unique($verifiedStudentQboIds));
    $invoiceScopeIds = array_merge($verifiedStudentQboIds, $completeHouseholdQboIds);
    $invoices = $invoiceScopeIds ? $this->client->getOpenInvoices($invoiceScopeIds) : [];

    return [
      'balance' => round($total, 2),
      'flagged' => $flagged,
      'invoices' => $invoices,
      'perHousehold' => $perHousehold,
    ];
  }

}
