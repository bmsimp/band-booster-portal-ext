<?php
// The portal's own screens, put somewhere a person can find them.
//
// Everything this extension adds to CiviCRM was, until now, reachable only by
// typing a URL: the QuickBooks connection page and the reconciliation findings
// existed and were linked from nowhere at all. That is a poor deal for §1's
// volunteer, who inherits this without the URLs and without anybody to ask.
//
// A top-level menu rather than something tucked inside Administer, for the same
// reason: the person who needs these screens is looking for "the band portal
// thing", not browsing a CRM's administration tree.
//
// Each item carries the permission of the screen it points at, so the menu
// shows a person exactly what they can actually open. A board member holding
// 'view all contacts' sees the findings; only the webmaster tier holding
// 'administer CiviCRM' sees the QuickBooks connection. A parent -- who holds
// plain 'access CiviCRM' and nothing else -- sees neither, and in any case
// never visits CiviCRM's backend.
//
// 'view all contacts' rather than the label 'access CiviCRM backend and API',
// which is what these entries said when first written: that string is not a
// permission key, so on WordPress it becomes a capability nobody has and the
// menu would have been invisible to everybody except a full administrator. See
// the long note in Civi/Api4/ReconFinding.php.

use CRM_Boosterportal_ExtensionUtil as E;

return [
  [
    'name' => 'Navigation_BoosterPortal',
    'entity' => 'Navigation',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'label' => E::ts('Band Portal'),
        'name' => 'boosterportal',
        // A container, so no URL of its own.
        'url' => NULL,
        // The weakest permission of anything beneath it: the menu should appear
        // for anybody who can open at least one thing inside it.
        'permission' => 'view all contacts',
        'parent_id' => NULL,
        'is_active' => TRUE,
        'has_separator' => 0,
        // After CiviCRM's own menus, before Support.
        'weight' => 95,
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'Navigation_BoosterPortal_Findings',
    'entity' => 'Navigation',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'label' => E::ts('Reconciliation Findings'),
        'name' => 'boosterportal_findings',
        // The SearchKit display defined in ReconDashboard.mgd.php. SearchKit
        // renders displays at this Angular route; the display itself runs with
        // the viewer's own permissions, so this link cannot show anybody more
        // than they are entitled to see.
        'url' => 'civicrm/search#/display/Recon_Findings/recon-findings',
        // The same permission ReconFinding::permissions() gates get() with. A
        // link that leads to a permission error is worse than no link.
        'permission' => 'view all contacts',
        'parent_id.name' => 'boosterportal',
        'is_active' => TRUE,
        'has_separator' => 0,
        'weight' => 10,
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'Navigation_BoosterPortal_Qbo',
    'entity' => 'Navigation',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'label' => E::ts('QuickBooks Connection'),
        'name' => 'boosterportal_qbo',
        'url' => 'civicrm/admin/boosterportal/qbo',
        // Matches the route's own access_arguments in xml/Menu/boosterportal.xml.
        'permission' => 'administer CiviCRM',
        'parent_id.name' => 'boosterportal',
        'is_active' => TRUE,
        'has_separator' => 0,
        'weight' => 20,
      ],
      'match' => ['name'],
    ],
  ],
];
