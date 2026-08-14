<?php
// Task 17 (§4.9/§7.3): the "Your students" panel on the parent dashboard —
// hand-authored directly against the same shape SearchKit's own UI export
// produces (verified empirically: built via SavedSearch.create/
// SearchDisplay.create against this dev site, confirmed the API accepted
// the shape, then deleted the UI copies and pasted the validated result
// here — the same authoring flow Task 13 Step 6 used for ReconDashboard).
//
// Entity Contact, filtered to Individuals carrying a QBO sub-customer id
// (Booster_QBO_Student.qbo_subcustomer_id IS NOT NULL) — i.e. students, not
// parents or households. Single column: display name. The plan's draft also
// mentioned a "Household name" column; that's deliberately left out — a
// parent has no ACL grant to Household or Relationship rows at all
// (boosterportal_civicrm_aclGroup() only ever grants the Booster_QBO_Student
// custom group; AclLeakTest::testRelationshipsOfOtherFamilyAreInvisible()
// documents that Relationship::get(TRUE) is always [] for a portal parent),
// so a household-name join would either error or silently return nothing —
// better to not promise a column the ACL model can't deliver.
//
// NO acl_bypass ANYWHERE IN THIS FILE (invariant 1, §3.3) — the whole
// security model of this page is that the display runs with the viewer's
// own permissions, and boosterportal_civicrm_aclWhereClause() (boosterportal.php)
// is what scopes the Contact rows to the logged-in parent's own students via
// the Portal_Parent_of permissioned relationship. AclLeakTest's
// testEveryDashboardSearchDisplayIsLeakFree() sweep is what guards this.
return [
  [
    'name' => 'SavedSearch_Your_Students',
    'entity' => 'SavedSearch',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Your_Students',
        'label' => 'Your students',
        'api_entity' => 'Contact',
        'api_params' => [
          'version' => 4,
          'select' => [
            'display_name',
          ],
          'orderBy' => [
            'display_name' => 'ASC',
          ],
          'where' => [
            ['contact_type', '=', 'Individual'],
            ['Booster_QBO_Student.qbo_subcustomer_id', 'IS NOT NULL'],
          ],
          'join' => [],
          'groupBy' => [],
          'having' => [],
        ],
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'SavedSearch_Your_Students_SearchDisplay_your-students-table',
    'entity' => 'SearchDisplay',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'your-students-table',
        'label' => 'Your students',
        'saved_search_id.name' => 'Your_Students',
        'type' => 'table',
        'settings' => [
          'description' => 'The students linked to your account.',
          'sort' => [
            ['display_name', 'ASC'],
          ],
          'limit' => 50,
          'pager' => [],
          'placeholder' => 5,
          'columns' => [
            [
              'type' => 'field',
              'key' => 'display_name',
              'label' => 'Name',
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
