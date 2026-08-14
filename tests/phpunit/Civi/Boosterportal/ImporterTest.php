<?php
namespace Civi\Boosterportal;

use Civi\Api4\Contact;
use Civi\Test;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Task 11: the one-time QBO -> CiviCRM family importer. Distinct from the
 * mirror (Mirror.php never touches contacts) — this is the console/admin-only
 * action that reads boosterportal_qbo_customer and creates Households,
 * students, and parents via FamilyBuilder, once, at cutover (Task 18).
 */
class ImporterTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return Test::headless()->installMe(__DIR__)->apply();
  }

  /**
   * Belt, on top of TransactionalInterface's per-test rollback — same
   * reasoning and same DELETE-not-TRUNCATE approach as MirrorTest::setUp():
   * CiviTestListener::startTest() opens the wrapping transaction before this
   * setUp() runs, so TRUNCATE's implicit COMMIT would defeat rollback
   * isolation for the rest of the test; DELETE participates normally.
   */
  protected function setUp(): void {
    parent::setUp();
    \CRM_Core_DAO::executeQuery('DELETE FROM boosterportal_qbo_customer');
    \CRM_Core_DAO::executeQuery('DELETE FROM boosterportal_qbo_balance_history');
    \CRM_Core_DAO::executeQuery('DELETE FROM boosterportal_login_token');
  }

  /**
   * NULLIF(%N, '') with single-quoted SQL literals — the pattern
   * Mirror::insertCustomer() proved works (see its docblock). The plan's
   * original draft used a double-quoted "" literal inside a single-quoted
   * PHP string, which risks MySQL treating "" as an (invalid, empty)
   * identifier under ANSI-quotes-flavoured sql_modes; reusing the
   * already-proven single-quote form avoids that entirely.
   */
  private function seedMirror(): void {
    $rows = [
      ['101', 'Smith, Pat', NULL, 'pat@example.org'],
      ['102', 'Smith, Sam', '101', NULL],
      ['103', 'Smith, Alex', '101', NULL],
    ];
    foreach ($rows as [$id, $name, $parent, $email]) {
      \CRM_Core_DAO::executeQuery(
        "INSERT INTO boosterportal_qbo_customer
           (qbo_id, display_name, active, parent_ref, balance, balance_with_jobs, email, synced_at)
         VALUES (%1, %2, 1, NULLIF(%3, ''), 0, NULL, NULLIF(%4, ''), NOW())",
        [
          1 => [$id, 'String'], 2 => [$name, 'String'],
          3 => [$parent ?? '', 'String'], 4 => [$email ?? '', 'String'],
        ]);
    }
  }

  public function testDryRunCreatesNothing(): void {
    $this->seedMirror();
    $before = (int) \CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_contact');
    $plan = (new Importer())->run(dryRun: TRUE);
    $after = (int) \CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_contact');
    $this->assertSame($before, $after);
    $this->assertSame(1, $plan['households']);
    $this->assertSame(2, $plan['students']);
  }

  public function testImportCreatesFamilyAndIsIdempotent(): void {
    $this->seedMirror();
    (new Importer())->run(dryRun: FALSE);

    $hh = Contact::get(FALSE)
      ->addWhere('Booster_QBO.qbo_customer_id', '=', '101')->execute()->single();
    $students = Contact::get(FALSE)
      ->addWhere('Booster_QBO_Student.qbo_subcustomer_id', 'IN', ['102', '103'])
      ->execute();
    $this->assertSame(2, $students->count());

    // Parent created from the QBO customer email, permissioned to both students.
    $parent = Contact::get(FALSE)
      ->addJoin('Email AS email', 'INNER')
      ->addWhere('email.email', '=', 'pat@example.org')
      ->addWhere('contact_type', '=', 'Individual')
      ->execute()->single();
    $rels = \Civi\Api4\Relationship::get(FALSE)
      ->addWhere('contact_id_a', '=', $parent['id'])
      ->addWhere('is_permission_a_b', '=', \CRM_Contact_BAO_Relationship::VIEW)
      ->execute();
    $this->assertSame(2, $rels->count());

    // Second run: no duplicates (matched on qbo ids).
    $summary = (new Importer())->run(dryRun: FALSE);
    $this->assertSame(0, $summary['created_households']);
    $this->assertSame(0, $summary['created_students']);
  }

  /**
   * Controller amendment 1: FamilyBuilder validates first_name/last_name are
   * both non-empty and throws \InvalidArgumentException otherwise.
   * Importer::splitName() produces an empty first_name for a single-token
   * display name (e.g. "Cher" has no comma and no space to split on), so a
   * mirror row shaped like that would previously blow up the whole run.
   * Requirement: the Importer catches that per-family and records a
   * human-actionable 'skipped' reason instead, and keeps going — a single
   * bad row in a QBO company file must not block every other family's import.
   */
  public function testSingleTokenDisplayNameIsSkippedNotFatal(): void {
    // Customer 201 ("Cher", single token) is the household/parent row — its
    // display_name splits into first_name '' / last_name 'Cher', which
    // FamilyBuilder::create() rejects. Customer 301 ("Jones, Robin") is a
    // normal well-formed family that must still import despite 201 failing.
    $rows = [
      ['201', 'Cher', NULL, 'cher@example.org'],
      ['202', 'Cher, Student', '201', NULL],
      ['301', 'Jones, Robin', NULL, 'robin@example.org'],
      ['302', 'Jones, Jamie', '301', NULL],
    ];
    foreach ($rows as [$id, $name, $parent, $email]) {
      \CRM_Core_DAO::executeQuery(
        "INSERT INTO boosterportal_qbo_customer
           (qbo_id, display_name, active, parent_ref, balance, balance_with_jobs, email, synced_at)
         VALUES (%1, %2, 1, NULLIF(%3, ''), 0, NULL, NULLIF(%4, ''), NOW())",
        [
          1 => [$id, 'String'], 2 => [$name, 'String'],
          3 => [$parent ?? '', 'String'], 4 => [$email ?? '', 'String'],
        ]);
    }

    $summary = (new Importer())->run(dryRun: FALSE);

    $this->assertSame(1, $summary['created_households'],
      'The well-formed Jones family must still import despite the Cher row failing.');
    $this->assertNotEmpty($summary['skipped']);
    $foundReason = FALSE;
    foreach ($summary['skipped'] as $reason) {
      if (str_contains($reason, '201') || str_contains($reason, 'Cher')) {
        $foundReason = TRUE;
        $this->assertMatchesRegularExpression('/does not split into first\+last|fix in QBO|import by hand/i', $reason);
      }
    }
    $this->assertTrue($foundReason, 'Expected a skipped-reason entry mentioning the Cher household.');

    // No contacts at all created for the Cher household.
    $cherHh = Contact::get(FALSE)
      ->addWhere('Booster_QBO.qbo_customer_id', '=', '201')->execute();
    $this->assertSame(0, $cherHh->count());

    // The Jones family DID import.
    $jonesHh = Contact::get(FALSE)
      ->addWhere('Booster_QBO.qbo_customer_id', '=', '301')->execute();
    $this->assertSame(1, $jonesHh->count());
  }

  /**
   * Fast-follow 1: 'no_students' is the Task 18 damage-report visibility for
   * a class of row that is otherwise completely invisible to run() — the
   * main parents query only ever selects a top-level customer that some
   * OTHER row references as parent_ref, so a childless active customer
   * (e.g. a family that paid dues but was never assigned a QBO sub-customer)
   * silently never appears anywhere in the summary today. Detection only:
   * it must not stop the well-formed Smith family from importing, and it
   * must populate in both dry-run and real runs (informational, not gated
   * on dryRun like the creation counts are).
   */
  public function testNoStudentsCustomerIsFlaggedInBothRunModesButOthersStillImport(): void {
    $this->seedMirror();
    // Customer 401 ("Duke, Patty") is active, top-level, and has zero
    // sub-customers referencing it — currently invisible to run() entirely.
    \CRM_Core_DAO::executeQuery(
      "INSERT INTO boosterportal_qbo_customer
         (qbo_id, display_name, active, parent_ref, balance, balance_with_jobs, email, synced_at)
       VALUES (%1, %2, 1, NULL, 0, NULL, NULLIF(%3, ''), NOW())",
      [1 => ['401', 'String'], 2 => ['Duke, Patty', 'String'], 3 => ['', 'String']]);

    $plan = (new Importer())->run(dryRun: TRUE);
    $this->assertNotEmpty($plan['no_students'] ?? NULL, 'no_students must populate on a dry run too.');
    $this->assertTrue(
      $this->containsReasonMentioning($plan['no_students'], '401'),
      'Expected a no_students entry mentioning customer 401.'
    );

    $summary = (new Importer())->run(dryRun: FALSE);
    $this->assertTrue($this->containsReasonMentioning($summary['no_students'], '401'));
    foreach ($summary['no_students'] as $reason) {
      if (str_contains($reason, '401')) {
        $this->assertStringContainsString('no sub-customers', $reason);
      }
    }

    // 401 was never touched — no household created for it.
    $noStudentsHh = Contact::get(FALSE)
      ->addWhere('Booster_QBO.qbo_customer_id', '=', '401')->execute();
    $this->assertSame(0, $noStudentsHh->count());

    // The well-formed Smith family (from seedMirror()) still imported normally.
    $this->assertSame(1, $summary['created_households']);
    $this->assertSame(2, $summary['created_students']);
  }

  /**
   * Fast-follow 1: 'duplicate_emails' is the other Task 18 damage-report
   * signal — MagicLink (Task 15) resolves a login email to exactly ONE
   * contact, so two different top-level customers sharing an email is a
   * real ambiguity a treasurer needs to see before cutover. Detection only:
   * both families must still import; this never blocks creation.
   */
  public function testDuplicateEmailAcrossFamiliesIsFlaggedButBothStillImport(): void {
    $rows = [
      ['501', 'Alpha, Ann', NULL, 'shared@example.org'],
      ['502', 'Alpha, Kid', '501', NULL],
      ['503', 'Beta, Bob', NULL, 'shared@example.org'],
      ['504', 'Beta, Kid', '503', NULL],
    ];
    foreach ($rows as [$id, $name, $parent, $email]) {
      \CRM_Core_DAO::executeQuery(
        "INSERT INTO boosterportal_qbo_customer
           (qbo_id, display_name, active, parent_ref, balance, balance_with_jobs, email, synced_at)
         VALUES (%1, %2, 1, NULLIF(%3, ''), 0, NULL, NULLIF(%4, ''), NOW())",
        [
          1 => [$id, 'String'], 2 => [$name, 'String'],
          3 => [$parent ?? '', 'String'], 4 => [$email ?? '', 'String'],
        ]);
    }

    $plan = (new Importer())->run(dryRun: TRUE);
    $this->assertNotEmpty($plan['duplicate_emails'] ?? NULL, 'duplicate_emails must populate on a dry run too.');
    $reason = $plan['duplicate_emails'][0];
    $this->assertStringContainsString('shared@example.org', $reason);
    $this->assertStringContainsString('501', $reason);
    $this->assertStringContainsString('503', $reason);
    $this->assertStringContainsString('MagicLink', $reason);

    $summary = (new Importer())->run(dryRun: FALSE);
    $this->assertNotEmpty($summary['duplicate_emails']);
    // Both families still imported normally despite the shared email.
    $this->assertSame(2, $summary['created_households']);
    $hh501 = Contact::get(FALSE)->addWhere('Booster_QBO.qbo_customer_id', '=', '501')->execute();
    $hh503 = Contact::get(FALSE)->addWhere('Booster_QBO.qbo_customer_id', '=', '503')->execute();
    $this->assertSame(1, $hh501->count());
    $this->assertSame(1, $hh503->count());
  }

  /**
   * Fast-follow 3: splitName()'s comma-less branch is a deliberate, narrow
   * design choice, not a bug — QBO's convention is "Last, First", so a
   * comma-less display name is already an anomaly the treasurer should
   * normalize; it is not worth blocking the whole family's import over.
   * "Bob Smith Jr" has no comma, so the naive split puts everything but the
   * last token into first_name: first "Bob Smith", last "Jr".
   */
  public function testCommaLessMultiTokenNameImportsWithNaiveSplitInsteadOfSkipping(): void {
    $rows = [
      ['601', 'Bob Smith Jr', NULL, 'bobsmithjr@example.org'],
      ['602', 'Casey Smith', '601', NULL],
    ];
    foreach ($rows as [$id, $name, $parent, $email]) {
      \CRM_Core_DAO::executeQuery(
        "INSERT INTO boosterportal_qbo_customer
           (qbo_id, display_name, active, parent_ref, balance, balance_with_jobs, email, synced_at)
         VALUES (%1, %2, 1, NULLIF(%3, ''), 0, NULL, NULLIF(%4, ''), NOW())",
        [
          1 => [$id, 'String'], 2 => [$name, 'String'],
          3 => [$parent ?? '', 'String'], 4 => [$email ?? '', 'String'],
        ]);
    }

    $summary = (new Importer())->run(dryRun: FALSE);
    $this->assertSame(1, $summary['created_households']);
    $this->assertEmpty($summary['skipped'], 'A comma-less name must import via naive split, not be skipped.');

    $parent = Contact::get(FALSE)
      ->addJoin('Email AS email', 'INNER')
      ->addWhere('email.email', '=', 'bobsmithjr@example.org')
      ->execute()->single();
    $this->assertSame('Bob Smith', $parent['first_name']);
    $this->assertSame('Jr', $parent['last_name']);
  }

  /**
   * @param string[] $reasons
   */
  private function containsReasonMentioning(array $reasons, string $needle): bool {
    foreach ($reasons as $reason) {
      if (str_contains($reason, $needle)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
