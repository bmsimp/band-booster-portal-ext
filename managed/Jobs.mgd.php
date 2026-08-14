<?php
return [
  [
    'name' => 'Job_boosterportal_mirror',
    'entity' => 'Job',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Booster Portal: nightly QBO mirror',
        'run_frequency' => 'Daily',
        'api_entity' => 'BoosterPortal',
        'api_action' => 'refreshMirror',
        'is_active' => TRUE,
        'description' => 'Pulls the QBO customer tree into boosterportal_qbo_customer. Reconciliation (Task 13) will run after this.',
      ],
      'match' => ['name'],
    ],
  ],
];
