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
 * R1 precision note (second security review): "complete" here is relative
 * to students CiviCRM itself knows about — a Portal_Parent_of/Household
 * Member of edge, a Booster_QBO_Student.qbo_subcustomer_id. If QBO has a
 * sub-customer CiviCRM has never heard of (never imported, never wired to a
 * Household Member of edge), the gate opens on a count that is complete by
 * CiviCRM's bookkeeping but not by QBO's, and the resulting aggregate could
 * still include that unknown student's balance. This is a pre-existing
 * trust boundary this gate does not and cannot close on its own — closing
 * the QBO-side half is reconciliation checks #1/#2 (Task 13), which MUST be
 * live before parents get the portal.
 *
 * R2 (second security review): a by-design partial view (this parent can
 * only see SOME of a household's students — structural, not fixable by
 * retrying) is a DIFFERENT signal from a genuine cross-check mismatch, and
 * conflating them into one 'flagged' bit meant every legitimate split
 * family fired the treasurer's mismatch flag forever, drowning out real
 * findings. The two are now separate fields: 'flagged' is reserved for an
 * actual BalanceWithJobs-vs-expected disagreement (warrants a WARNING log
 * and the treasurer's attention); 'partial' marks a by-design incomplete
 * view (an INFO log — informational, not actionable — see GetMyBalance)
 * and never sets 'flagged'.
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
   *   $completeHousehold is also TRUE). Deduplicated internally (R3) so a
   *   repeated id is never summed twice.
   * @param bool $completeHousehold
   *   TRUE only when $studentQboIds is this household's entire active
   *   student set, not merely the subset the caller can see. Gates the
   *   §4.6 cross-check: FALSE makes reading/comparing BalanceWithJobs
   *   structurally unreachable (see below) rather than merely unused.
   * @return array{balance: float, flagged: bool, partial: bool, detail: array}
   *   'flagged': a genuine BalanceWithJobs-vs-expected mismatch. 'partial':
   *   a by-design incomplete view — the two never overlap (R2).
   */
  public function familyBalance(string $householdQboId, array $studentQboIds, bool $completeHousehold): array {
    // R3: the same array_unique() protection invoice scoping already
    // applies (balanceForHouseholds(), below), so a duplicate qbo id in the
    // list — defensive; should not ordinarily happen — is never summed
    // twice.
    $studentQboIds = array_values(array_unique($studentQboIds));

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
      // parent CAN see, nothing else. R2: this is 'partial', not 'flagged'
      // — a structural fact about what this parent can see, not a
      // reconciliation mismatch; reconciliation (not this parent's screen)
      // is where the full household picture gets checked.
      return ['balance' => round($studentSum, 2), 'flagged' => FALSE, 'partial' => TRUE,
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
      // §3.1: absent is not zero. Skip the cross-check, surface the sum,
      // flag — this IS a genuine reconciliation finding (missing data on a
      // COMPLETE household), not a by-design partial view, so 'flagged' is
      // correct here (unlike the incomplete-household branch above).
      return ['balance' => round($studentSum, 2), 'flagged' => TRUE, 'partial' => FALSE,
        'detail' => ['student_sum' => $studentSum, 'balance_with_jobs' => NULL,
          'reason' => 'BalanceWithJobs not returned']];
    }

    $agrees = abs($bwj - $expected) < 0.005;
    $display = $agrees ? $expected : max($bwj, $expected);
    return [
      'balance' => round($display, 2),
      'flagged' => !$agrees,
      'partial' => FALSE,
      'detail' => ['student_sum' => $studentSum, 'parent_own' => $parentOwn,
        'balance_with_jobs' => $bwj],
    ];
  }

  /**
   * Aggregates familyBalance() across every billing household a parent has
   * verified students in (I2), and owns the invoice-scoping rule (M3).
   *
   * @param array<array{qbo_customer_id: string, verified_student_qbo_ids: string[], complete: bool}> $households
   * @return array{balance: float, flagged: bool, partial: bool, invoices: array, perHousehold: array}
   *   'flagged': TRUE if ANY household had a genuine cross-check mismatch.
   *   'partial': TRUE if ANY household was a by-design incomplete view. The
   *   two are independent — a parent can have one household flagged AND a
   *   different household partial at the same time. 'perHousehold' carries
   *   each household's own familyBalance() result (plus its
   *   qbo_customer_id/complete flag) for the caller to log detail per
   *   household without re-deriving anything.
   */
  public function balanceForHouseholds(array $households): array {
    $total = 0.0;
    $flagged = FALSE;
    $partial = FALSE;
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
      if ($r['partial']) {
        $partial = TRUE;
      }
      if ($hh['complete']) {
        // M3: a household's own qbo id is only ever looked up (invoices
        // included) once it has independently passed the complete-set gate
        // — same containment reasoning as the BalanceWithJobs read above.
        $completeHouseholdQboIds[] = $hh['qbo_customer_id'];
      }
      $perHousehold[] = ['qbo_customer_id' => $hh['qbo_customer_id'], 'complete' => $hh['complete']] + $r;
    }

    // R3: same dedup as familyBalance()'s own list — a student verified
    // under more than one household entry (see FamilyResolver's own
    // dedup, which should normally prevent this) is still never summed or
    // invoiced twice here either.
    $verifiedStudentQboIds = array_values(array_unique($verifiedStudentQboIds));
    $invoiceScopeIds = array_merge($verifiedStudentQboIds, $completeHouseholdQboIds);
    $invoices = $invoiceScopeIds ? $this->client->getOpenInvoices($invoiceScopeIds) : [];

    return [
      'balance' => round($total, 2),
      'flagged' => $flagged,
      'partial' => $partial,
      'invoices' => $invoices,
      'perHousehold' => $perHousehold,
    ];
  }

}
