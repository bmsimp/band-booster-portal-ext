<?php
namespace Civi\Boosterportal;

/**
 * Nightly QBO snapshot (§5.4). Writes ONLY boosterportal_qbo_* tables.
 * NOT the initial data load (that is Importer.php) — do not conflate them.
 */
class Mirror {

  public function __construct(private QboClientInterface $client) {
  }

  /** @return int customers mirrored */
  public function refresh(): int {
    $now = date('Y-m-d H:i:s');
    $today = date('Y-m-d');
    $rows = [];
    foreach ($this->client->listAllCustomers() as $c) {
      $rows[] = $c;
    }
    // All-or-nothing swap so reconciliation never reads a half-updated snapshot (§6).
    \CRM_Core_Transaction::create()->run(function () use ($rows, $now, $today) {
      \CRM_Core_DAO::executeQuery('DELETE FROM boosterportal_qbo_customer');
      foreach ($rows as $c) {
        $this->insertCustomer($c, $now);
        $this->insertHistory($c, $today);
      }
    });
    return count($rows);
  }

  /**
   * parent_ref/email are String columns: NULLIF(%N, '') correctly turns a
   * bound '' into a real SQL NULL (verified empirically — CRM_Core_DAO's
   * 'String' validator passes '' through unchanged, so the placeholder
   * substitution is just `NULLIF('', '')`, which MySQL evaluates to NULL).
   *
   * balance_with_jobs can NOT use the same trick: it's bound as 'Float', and
   * CRM_Core_DAO::composeQuery() runs CRM_Utils_Type::validate() on every
   * bound param — including unused ones — via CRM_Utils_Rule::numeric(),
   * which rejects '' outright and throws
   * "... is not of the type Float" before the query ever reaches MySQL.
   * BalanceWithJobs is nullable by design (QboClientInterface — it's a
   * non-default QBO field, §3.1, and its absence must be visible, not
   * coerced to 0.00), so a real customer row with no jobs balance would
   * always hit this and Mirror::refresh() would hard-fail. Instead, decide
   * NULL-vs-value in PHP and swap in a literal `NULL` SQL fragment when the
   * value is absent; the placeholder is still bound with a harmless dummy
   * float (0) in that case purely so composeQuery's blanket validation of
   * every param doesn't throw — it is never actually written, since the
   * query text doesn't reference it.
   */
  private function insertCustomer(array $c, string $now): void {
    $balanceWithJobs = $c['BalanceWithJobs'] ?? NULL;
    $balanceWithJobsSql = $balanceWithJobs !== NULL ? '%6' : 'NULL';
    \CRM_Core_DAO::executeQuery(
      "INSERT INTO boosterportal_qbo_customer
         (qbo_id, display_name, active, parent_ref, balance, balance_with_jobs, email, synced_at)
       VALUES (%1, %2, %3, NULLIF(%4, ''), %5, {$balanceWithJobsSql}, NULLIF(%7, ''), %8)",
      [
        1 => [$c['Id'], 'String'],
        2 => [$c['DisplayName'], 'String'],
        3 => [(int) $c['Active'], 'Integer'],
        4 => [$c['ParentRef'] ?? '', 'String'],
        5 => [$c['Balance'], 'Float'],
        6 => [$balanceWithJobs ?? 0, 'Float'],
        7 => [$c['PrimaryEmailAddr'] ?? '', 'String'],
        8 => [$now, 'String'],
      ]
    );
  }

  /**
   * Upsert, keyed on boosterportal_qbo_balance_history's UNIQUE KEY uq_day
   * (qbo_id, synced_on): history is a PER-DAY snapshot (§5.4 design intent).
   * A same-day re-run (manual re-trigger, or the nightly job running twice)
   * updates that day's row to the latest values rather than appending a
   * second row — "latest wins" for the day, which is what makes the table
   * diffable day-over-day without also needing to dedupe within a day.
   * Same balance_with_jobs NULL handling as insertCustomer() above, and for
   * the same reason.
   */
  private function insertHistory(array $c, string $today): void {
    $balanceWithJobs = $c['BalanceWithJobs'] ?? NULL;
    $balanceWithJobsSql = $balanceWithJobs !== NULL ? '%3' : 'NULL';
    \CRM_Core_DAO::executeQuery(
      "INSERT INTO boosterportal_qbo_balance_history (qbo_id, balance, balance_with_jobs, synced_on)
       VALUES (%1, %2, {$balanceWithJobsSql}, %4)
       ON DUPLICATE KEY UPDATE balance = VALUES(balance), balance_with_jobs = VALUES(balance_with_jobs)",
      [
        1 => [$c['Id'], 'String'],
        2 => [$c['Balance'], 'Float'],
        3 => [$balanceWithJobs ?? 0, 'Float'],
        4 => [$today, 'String'],
      ]
    );
  }

}
