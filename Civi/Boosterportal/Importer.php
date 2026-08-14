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
      'created_students' => 0, 'skipped' => []];

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

  /** "Smith, Pat" → first/last; single token lands in last_name. */
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
