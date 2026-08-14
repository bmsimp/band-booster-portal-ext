<?php
namespace Civi\Boosterportal;

/**
 * The ONLY place balance maths lives (§4.6). No dollar figure shown to a
 * parent is ever computed from CiviCRM data (§4.5) — inputs here are live QBO.
 */
class BalanceService {

  public function __construct(private QboClientInterface $client) {
  }

  /**
   * @param string $householdQboId  the family's parent Customer id
   * @param string[] $studentQboIds the students' sub-customer ids
   * @return array{balance: float, flagged: bool, detail: array}
   */
  public function familyBalance(string $householdQboId, array $studentQboIds): array {
    $studentSum = 0.0;
    foreach ($studentQboIds as $sid) {
      $c = $this->client->getCustomer($sid);
      $studentSum += $c ? $c['Balance'] : 0.0;
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

}
