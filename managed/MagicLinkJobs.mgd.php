<?php
// Task 15 adversarial review, MINOR-3: daily housekeeping for the
// boosterportal_login_token table — see MagicLink::purgeOldTokens().
return [
  [
    'name' => 'Job_boosterportal_purgeLoginTokens',
    'entity' => 'Job',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Booster Portal: purge old login tokens',
        'run_frequency' => 'Daily',
        'api_entity' => 'BoosterPortal',
        'api_action' => 'purgeLoginTokens',
        'is_active' => TRUE,
        'description' => 'Deletes used/expired boosterportal_login_token rows older than 24 hours.',
      ],
      'match' => ['name'],
    ],
  ],
];
