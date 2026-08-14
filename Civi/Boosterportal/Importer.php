<?php
namespace Civi\Boosterportal;

use Civi\Api4\Contact;

/**
 * The ONE-TIME initial load (§5.2): reads the mirror table, creates Households
 * (top-level customers with jobs), student Individuals (sub-customers), one
 * parent Individual per customer email, and permissioned edges via
 * FamilyBuilder. Idempotent: existing qbo ids are skipped. After this runs,
 * CiviCRM is master for NEW families only; QBO stays master for money, forever.
 *
 * checkPermissions FALSE throughout: this is a console/admin-only action,
 * gated by 'administer CiviCRM' at the API layer (ImportFamilies action /
 * BoosterPortal::permissions()), not reachable by a parent-tier user. See
 * InvariantTest's allowlist entry for this file.
 */
class Importer {

  public function run(bool $dryRun = TRUE): array {
    $summary = ['households' => 0, 'students' => 0, 'created_households' => 0,
      'created_students' => 0, 'skipped' => [], 'no_students' => [], 'duplicate_emails' => []];

    // Fast-follow 1: Task 18 damage-report visibility. Both of these are
    // DETECTION ONLY — populated in dry-run and real runs alike, and neither
    // one changes what gets created below.
    $this->detectNoStudents($summary);
    $this->detectDuplicateEmails($summary);

    $parents = \CRM_Core_DAO::executeQuery(
      'SELECT * FROM boosterportal_qbo_customer
       WHERE parent_ref IS NULL AND active = 1
         AND qbo_id IN (SELECT DISTINCT parent_ref FROM boosterportal_qbo_customer WHERE parent_ref IS NOT NULL)'
    )->fetchAll();

    foreach ($parents as $p) {
      $subs = \CRM_Core_DAO::executeQuery(
        'SELECT * FROM boosterportal_qbo_customer WHERE parent_ref = %1 AND active = 1',
        [1 => [$p['qbo_id'], 'String']])->fetchAll();
      $summary['households']++;
      $summary['students'] += count($subs);

      if ($dryRun) {
        continue;
      }

      $existingHh = Contact::get(FALSE)
        ->addWhere('Booster_QBO.qbo_customer_id', '=', $p['qbo_id'])
        ->execute()->first();
      if ($existingHh) {
        // Household known; only add students whose qbo id is new.
        $newSubs = array_filter($subs, fn($s) => !Contact::get(FALSE)
          ->addWhere('Booster_QBO_Student.qbo_subcustomer_id', '=', $s['qbo_id'])
          ->execute()->first());
        if (!$newSubs) {
          continue;
        }
        $summary['skipped'][] = "Household {$p['qbo_id']} exists with new sub-customers — resolve by hand: "
          . implode(', ', array_column($newSubs, 'qbo_id'));
        continue;
      }

      if (empty($p['email'])) {
        $summary['skipped'][] = "Customer {$p['qbo_id']} ({$p['display_name']}) has no email — no parent login possible; import by hand";
        continue;
      }

      try {
        FamilyBuilder::create([
          'household' => ['name' => $p['display_name'], 'qbo_customer_id' => $p['qbo_id']],
          'parents' => [self::splitName($p['display_name']) + ['email' => $p['email']]],
          'students' => array_map(
            fn($s) => self::splitName($s['display_name']) + ['qbo_subcustomer_id' => $s['qbo_id']],
            $subs),
        ]);
      }
      catch (\InvalidArgumentException | \CRM_Core_Exception $e) {
        // FamilyBuilder validates first_name/last_name up front and throws
        // before any write happens (\InvalidArgumentException), or a write
        // itself can fail (\CRM_Core_Exception) — either way this single
        // family must not abort the whole run. The most common real-world
        // trigger is a single-token QBO display name (e.g. "Cher"): splitName()
        // below puts the whole token in last_name and leaves first_name empty,
        // which FamilyBuilder rejects.
        $summary['skipped'][] = "Customer {$p['qbo_id']}: display name '{$p['display_name']}' "
          . "does not split into first+last — fix in QBO or import by hand ({$e->getMessage()})";
        continue;
      }
      $summary['created_households']++;
      $summary['created_students'] += count($subs);
    }
    return $summary;
  }

  /**
   * Fast-follow 1: the main parents query above (SELECT ... WHERE qbo_id IN
   * (SELECT DISTINCT parent_ref ...)) only ever surfaces a top-level customer
   * that some OTHER row references as parent_ref. A top-level, active QBO
   * customer with zero active sub-customers — e.g. a family that pays dues
   * but was never assigned a QBO sub-customer for a student — is therefore
   * completely invisible to run() otherwise: not imported (correctly — there
   * is no student to build), but also never mentioned anywhere. This is
   * exactly the kind of gap Task 18's cutover damage report needs surfaced,
   * so it is reported here, independent of $dryRun.
   */
  private function detectNoStudents(array &$summary): void {
    $rows = \CRM_Core_DAO::executeQuery(
      "SELECT * FROM boosterportal_qbo_customer
       WHERE parent_ref IS NULL AND active = 1
         AND qbo_id NOT IN (
           SELECT DISTINCT parent_ref FROM boosterportal_qbo_customer
           WHERE parent_ref IS NOT NULL AND active = 1
         )"
    )->fetchAll();
    foreach ($rows as $c) {
      $summary['no_students'][] = "Customer {$c['qbo_id']} ({$c['display_name']}): "
        . 'no sub-customers — nothing to import; verify no balance owed';
    }
  }

  /**
   * Fast-follow 1: MagicLink (Task 15) resolves a login email to exactly ONE
   * contact. Two different top-level customers sharing the same email in the
   * mirror is a real ambiguity the treasurer needs to see before cutover —
   * detection only, this never blocks either family from importing normally
   * (each gets its own parent contact; CiviCRM itself has no problem with two
   * contacts sharing an email address).
   */
  private function detectDuplicateEmails(array &$summary): void {
    $rows = \CRM_Core_DAO::executeQuery(
      "SELECT email, GROUP_CONCAT(qbo_id ORDER BY qbo_id SEPARATOR ', ') AS ids
       FROM boosterportal_qbo_customer
       WHERE parent_ref IS NULL AND active = 1 AND email IS NOT NULL
       GROUP BY email
       HAVING COUNT(*) > 1"
    )->fetchAll();
    foreach ($rows as $d) {
      $summary['duplicate_emails'][] = "{$d['email']} shared by customers {$d['ids']} — "
        . 'MagicLink (Task 15) resolves an email to ONE contact; fix in QBO or accept that only one family can log in with it';
    }
  }

  /**
   * "Smith, Pat" → first/last (QBO's own "Last, First" convention). A
   * single token (e.g. "Cher") lands entirely in last_name, leaving
   * first_name empty — FamilyBuilder rejects that and the family is skipped
   * (see the catch block above).
   *
   * Fast-follow 3: a comma-less display name with MORE than one token (e.g.
   * "Bob Smith Jr", not QBO's "Last, First" convention) is NOT skipped: it
   * gets a naive split instead — everything but the last token becomes
   * first_name, the last token becomes last_name ("Bob Smith" / "Jr"). This
   * is a deliberate choice, not an oversight: QBO's convention is
   * "Last, First", so a comma-less multi-token name is already an anomaly
   * the treasurer should normalize in QBO; it's a cosmetic name-field split,
   * not a missing-data problem, so it is not worth blocking the whole
   * family's import over the way an empty first_name is.
   */
  private static function splitName(string $display): array {
    if (str_contains($display, ',')) {
      [$last, $first] = array_map('trim', explode(',', $display, 2));
      return ['first_name' => $first, 'last_name' => $last];
    }
    $parts = preg_split('/\s+/', trim($display));
    return ['first_name' => count($parts) > 1 ? implode(' ', array_slice($parts, 0, -1)) : '',
      'last_name' => end($parts)];
  }

}
