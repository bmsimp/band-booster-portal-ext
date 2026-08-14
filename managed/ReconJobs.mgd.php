<?php
// §6 (Task 13): the reconciliation report's two scheduled jobs. Kept
// Daily/Hourly rather than tunable-from-UI (design §5.4's stated wish) —
// see the design note in the Task 13 plan section for why: the 18 checks
// ship as tested PHP, not SavedSearches, for determinism/emailability at
// Phase 1 size; the seam for a future SavedSearch-backed check stays open.
$job = fn(string $name, string $action, string $freq, string $desc) => [
  'name' => "Job_boosterportal_{$action}",
  'entity' => 'Job',
  'cleanup' => 'never',
  'update' => 'unmodified',
  'params' => [
    'version' => 4,
    'values' => [
      'name' => $name, 'run_frequency' => $freq,
      'api_entity' => 'BoosterPortal', 'api_action' => $action,
      'is_active' => TRUE, 'description' => $desc,
    ],
    'match' => ['name'],
  ],
];
return [
  $job('Booster Portal: nightly reconciliation', 'runRecon', 'Daily',
    'Checks 1,2,5-18 over the fresh mirror. Runs right after the nightly mirror job (§6) — keep both Daily so they share the cron slot, mirror first (jobs run in id order).'),
  $job('Booster Portal: hourly invariant checks', 'runReconHourly', 'Hourly',
    'Checks 3 (acl_bypass), 4 (ACL canary), and 4b (out-of-band permissioned rows) — CiviCRM-only, highest severity (§6).'),
];
