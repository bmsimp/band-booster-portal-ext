<?php
// QBO linkage fields (§5.2). Two groups: one on Households, one on Individuals.
// Read-only in the UI: these are wired by the importer and audited by reconciliation,
// not hand-edited.
return [
  [
    'name' => 'CustomGroup_Booster_QBO',
    'entity' => 'CustomGroup',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Booster_QBO',
        'title' => 'QuickBooks Link (Household)',
        'extends' => 'Household',
        'style' => 'Inline',
        'is_active' => TRUE,
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'CustomField_qbo_customer_id',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'Booster_QBO',
        'name' => 'qbo_customer_id',
        'label' => 'QBO Customer ID',
        'data_type' => 'String',
        'html_type' => 'Text',
        'is_searchable' => TRUE,
        'is_view' => TRUE,
      ],
      'match' => ['name', 'custom_group_id'],
    ],
  ],
  [
    'name' => 'CustomGroup_Booster_QBO_Student',
    'entity' => 'CustomGroup',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Booster_QBO_Student',
        'title' => 'QuickBooks Link (Student)',
        'extends' => 'Individual',
        'style' => 'Inline',
        'is_active' => TRUE,
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'CustomField_qbo_subcustomer_id',
    'entity' => 'CustomField',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'Booster_QBO_Student',
        'name' => 'qbo_subcustomer_id',
        'label' => 'QBO Sub-customer ID',
        'data_type' => 'String',
        'html_type' => 'Text',
        'is_searchable' => TRUE,
        'is_view' => TRUE,
      ],
      'match' => ['name', 'custom_group_id'],
    ],
  ],
  [
    'name' => 'RelationshipType_Portal_Parent_of',
    'entity' => 'RelationshipType',
    'cleanup' => 'never',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name_a_b' => 'Portal_Parent_of',
        'label_a_b' => 'Portal Parent of',
        'name_b_a' => 'Portal_Child_of',
        'label_b_a' => 'Portal Child of',
        'description' => 'Portal ACL edge: parent (A) may view student (B). Created by the importer; audited by reconciliation.',
        'contact_type_a' => 'Individual',
        'contact_type_b' => 'Individual',
        'is_active' => TRUE,
      ],
      'match' => ['name_a_b'],
    ],
  ],
];
