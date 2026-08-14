<?php
// §6 (Task 13): the webmaster's landing view over ReconFinding — hand-authored
// rather than exported from the SearchKit UI (the plan's suggested authoring
// flow), because ReconFinding is a DAO-less, read-only Api4 entity with no
// underlying CiviCRM entity_type registration for SearchKit's builder to pick
// up automatically; the shape below matches the managed-export format
// SearchKit itself produces (verified against core's own exports, e.g.
// standaloneusers/managed/SavedSearch_Administer_UserAccounts.mgd.php) and
// was validated by running SearchDisplay.run against it under an admin
// session (Task 13 report has the command + output).
//
// No acl_bypass anywhere in this file (invariant 1, §3.3) — ReconFinding's
// own permissions() gate ('access CiviCRM backend and API' for get(),
// Civi/Api4/ReconFinding.php) is what scopes this display to the webmaster
// tier; the display itself runs with the viewer's permissions.
return [
  [
    'name' => 'SavedSearch_Recon_Findings',
    'entity' => 'SavedSearch',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Recon_Findings',
        'label' => 'Reconciliation Findings',
        'api_entity' => 'ReconFinding',
        // ReconFinding is a DAO-less entity (Civi/Api4/ReconFinding.php,
        // Generic\BasicGetAction over a hand-rolled query) — it only
        // supports select/where/orderBy/limit, NOT groupBy/having/join
        // (those come from DAOGetAction traits a real CRUD entity gets and
        // this one doesn't). Including them here — even as empty arrays,
        // the shape core's own SavedSearch exports use for ordinary
        // DAO entities — fails outright ("Unknown api parameter: groupBy")
        // rather than being silently ignored; found empirically running
        // SearchDisplay.run against this exact search (Task 13 report).
        'api_params' => [
          'version' => 4,
          'select' => [
            'id',
            'check_num',
            'severity',
            'title',
            'detail',
            'found_at',
          ],
          'orderBy' => [],
          'where' => [],
        ],
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'SavedSearch_Recon_Findings_SearchDisplay_recon-findings',
    'entity' => 'SearchDisplay',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'recon-findings',
        'label' => 'Reconciliation Findings',
        'saved_search_id.name' => 'Recon_Findings',
        'type' => 'table',
        'settings' => [
          'description' => 'Findings from the nightly (checks 1,2,5-18) and hourly (checks 3,4,4b) reconciliation runs. CRITICAL findings are also emailed to the webmaster.',
          'sort' => [
            ['severity', 'ASC'],
            ['check_num', 'ASC'],
          ],
          'limit' => 50,
          'pager' => [],
          'placeholder' => 5,
          'columns' => [
            [
              'type' => 'field',
              'key' => 'severity',
              'label' => 'Severity',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'check_num',
              'label' => 'Check',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'title',
              'label' => 'Finding',
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'detail',
              'label' => 'Detail',
              'sortable' => FALSE,
            ],
            [
              'type' => 'field',
              'key' => 'found_at',
              'label' => 'Found',
              'sortable' => TRUE,
            ],
          ],
          'actions' => [],
          'classes' => [
            'table',
            'table-striped',
          ],
          'toolbar' => [],
          'button' => NULL,
        ],
      ],
      'match' => [
        'saved_search_id',
        'name',
      ],
    ],
  ],
];
