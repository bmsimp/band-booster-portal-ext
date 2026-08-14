<?php
declare(strict_types = 1);

// phpcs:disable PSR1.Files.SideEffects
require_once 'boosterportal.civix.php';
// phpcs:enable

use CRM_Boosterportal_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function boosterportal_civicrm_config(\CRM_Core_Config $config): void {
  _boosterportal_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function boosterportal_civicrm_install(): void {
  _boosterportal_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function boosterportal_civicrm_enable(): void {
  _boosterportal_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_aclWhereClause().
 *
 * §3.2 requires permissioned relationships (Portal_Parent_of, is_permission_a_b/b_a)
 * to be enforced at the APIv4 query layer, i.e. in search/list results, not just
 * single-record access checks. CiviCRM core only wires first-degree relationship
 * permissioning into CRM_Contact_BAO_Contact_Permission::allow()/allowList() (used
 * for direct, known-id checks); the ACL contact cache that backs bulk queries
 * (Contact::get(), and everything built on CRM_Contact_BAO_Contact::addSelectWhereClause())
 * is built purely from group-based ACLs via CRM_ACL_BAO_ACL::whereClause() and never
 * consults civicrm_relationship at all. Without this hook, a parent with only
 * "access CiviCRM" cannot see their own child in any list/search — the fixture's
 * permissioned relationship exists but is invisible to the query layer. This hook
 * closes that gap by mirroring CRM_Contact_BAO_Contact_Permission::relationshipList()'s
 * logic directly into the ACL where-clause hook, so it is honoured everywhere ACLs
 * are checked (search, SearchKit displays, joins), not only on direct-id lookups.
 *
 * Users who already hold blanket 'view all contacts'/'edit all contacts' never reach
 * this hook — CRM_ACL_API::whereClause() short-circuits before CRM_ACL_BAO_ACL::whereClause()
 * is invoked — so this only ever adds visibility for low-privilege portal users.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_aclWhereClause/
 */
function boosterportal_civicrm_aclWhereClause(int $type, array &$tables, array &$whereTables, ?int &$contactID, ?string &$whereClause): void {
  if (empty($contactID)) {
    return;
  }

  // CRM_Core_Permission::VIEW is satisfied by either a VIEW or an EDIT relationship
  // permission; anything else (i.e. EDIT) requires an EDIT relationship permission.
  // This mirrors CRM_Contact_BAO_Contact_Permission::relationshipList() exactly.
  if ($type === CRM_Core_Permission::VIEW) {
    $isPermCondition = 'IN (' . CRM_Contact_BAO_Relationship::EDIT . ',' . CRM_Contact_BAO_Relationship::VIEW . ')';
  }
  else {
    $isPermCondition = '= ' . CRM_Contact_BAO_Relationship::EDIT;
  }

  $relationshipClause = "contact_a.id IN (
    SELECT contact_id_b FROM civicrm_relationship
    WHERE contact_id_a = {$contactID} AND is_active = 1 AND is_permission_a_b {$isPermCondition}
    UNION
    SELECT contact_id_a FROM civicrm_relationship
    WHERE contact_id_b = {$contactID} AND is_active = 1 AND is_permission_b_a {$isPermCondition}
  )";

  $whereClause = $whereClause ? "({$whereClause} OR {$relationshipClause})" : $relationshipClause;
}

/**
 * Implements hook_civicrm_aclGroup().
 *
 * Custom-field visibility is a second, independent ACL gate from contact-row
 * visibility (CRM_Core_Permission::customGroup()): by default a contact with
 * neither 'access all custom data' nor 'administer CiviCRM data' can see NO
 * custom groups at all, regardless of the aclWhereClause hook above. Without
 * this, Booster_QBO / Booster_QBO_Student are simply absent from getFields()
 * for a portal parent and Api4 rejects any select of their fields as unknown
 * ("Invalid field"), even for the parent's own student.
 *
 * The QBO linkage fields need to be readable by any authenticated, non-admin
 * user so the portal dashboard (Task 17) can display them — row-level privacy
 * is still enforced separately by hook_civicrm_aclWhereClause() above, which
 * gates which *contacts* (and therefore which field values) are reachable at
 * all, so granting blanket VIEW on these two groups here does not by itself
 * expose another family's data.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_aclGroup/
 */
function boosterportal_civicrm_aclGroup(int $type, ?int $contactID, string $tableName, array &$allGroups, array &$currentGroups): void {
  if ($tableName !== 'civicrm_custom_group' || empty($contactID) || $type !== CRM_ACL_API::VIEW) {
    return;
  }
  $groupIds = (array) CRM_Core_DAO::executeQuery(
    "SELECT id FROM civicrm_custom_group WHERE name IN ('Booster_QBO', 'Booster_QBO_Student')"
  )->fetchMap('id', 'id');
  $currentGroups = array_unique(array_merge($currentGroups, $groupIds));
}
