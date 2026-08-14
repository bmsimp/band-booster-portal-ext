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
 * The relationship_type_id of Portal_Parent_of, memoized for the life of the
 * request. Used by both the aclWhereClause hook (to scope the ACL grant to
 * this one relationship type) and the pre-hook guard (to reject permissioned
 * relationships on any other type). A test harness that creates/renames the
 * RelationshipType mid-run should unset Civi::$statics['boosterportal'] to
 * force a re-lookup, same as the contact permission cache below.
 *
 * @return int|null
 */
function _boosterportal_portal_parent_relationship_type_id(): ?int {
  if (!isset(Civi::$statics['boosterportal']['portal_parent_relationship_type_id'])) {
    $id = CRM_Core_DAO::singleValueQuery(
      "SELECT id FROM civicrm_relationship_type WHERE name_a_b = 'Portal_Parent_of'"
    );
    Civi::$statics['boosterportal']['portal_parent_relationship_type_id'] = $id ? (int) $id : NULL;
  }
  return Civi::$statics['boosterportal']['portal_parent_relationship_type_id'];
}

/**
 * Implements hook_civicrm_aclWhereClause().
 *
 * §3.2 requires the permissioned Portal_Parent_of relationship (is_permission_a_b)
 * to be enforced at the APIv4 query layer, i.e. in search/list results, not just
 * single-record access checks. CiviCRM core only wires first-degree relationship
 * permissioning into CRM_Contact_BAO_Contact_Permission::allow()/allowList() (used
 * for direct, known-id checks); the ACL contact cache that backs bulk queries
 * (Contact::get(), and everything built on CRM_Contact_BAO_Contact::addSelectWhereClause())
 * is built purely from group-based ACLs via CRM_ACL_BAO_ACL::whereClause() and never
 * consults civicrm_relationship at all. Without this hook, a parent with only
 * "access CiviCRM" cannot see their own child in any list/search — the fixture's
 * permissioned relationship exists but is invisible to the query layer. This hook
 * closes that gap, but ONLY for Portal_Parent_of — it is honoured wherever
 * CRM_ACL_API::whereClause() is reached (this excludes callers that pass
 * $skipAcls / acl_bypass, and it does not affect the separate direct-id path
 * through CRM_Contact_BAO_Contact_Permission::relationshipList(), which already
 * worked and is intentionally not restricted to Portal_Parent_of).
 *
 * Users who already hold blanket 'view all contacts'/'edit all contacts' never reach
 * this hook — CRM_ACL_API::whereClause() short-circuits before CRM_ACL_BAO_ACL::whereClause()
 * is invoked — so this only ever adds visibility for low-privilege portal users.
 *
 * Scoping (security review C2/I4, after a stock 'Child of' relationship type with
 * an accidentally-ticked permission checkbox was shown to leak across families):
 *  - relationship_type_id is restricted to Portal_Parent_of specifically, never
 *    "any relationship type with a permission bit set".
 *  - The grant is directional only: parent is always contact_id_a, student is
 *    always contact_id_b (§4.4). There is no is_permission_b_a / reverse-direction
 *    UNION half — that was the other half of the leak.
 *  - $type is an explicit whitelist (VIEW or EDIT); anything else returns without
 *    adding a clause and falls through to core's existing deny-all.
 *  - Relationships must be currently active by date (I3): a disabled/expired edge
 *    must not grant access. The scheduled job "Disable expired relationships"
 *    (Job.disable_expired_relationships) flips is_active off once end_date has
 *    passed, but this hook does not rely on that job alone — it also filters on
 *    start_date/end_date directly, since the job only runs periodically and the
 *    window between expiry and the next cron run must not be a hole. THIS JOB
 *    MUST STAY ENABLED IN PRODUCTION; this hook's date filter is a second layer,
 *    not a replacement for it.
 *  - See hook_civicrm_post() below for cache invalidation on relationship writes,
 *    and hook_civicrm_pre() for the guard against setting permission bits on any
 *    relationship type other than Portal_Parent_of.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_aclWhereClause/
 */
function boosterportal_civicrm_aclWhereClause(int $type, array &$tables, array &$whereTables, ?int &$contactID, ?string &$whereClause): void {
  if (empty($contactID)) {
    return;
  }
  $contactID = (int) $contactID;

  // Explicit whitelist: CRM_Core_Permission::VIEW is satisfied by either a VIEW
  // or an EDIT relationship permission; CRM_Core_Permission::EDIT requires EDIT.
  // Anything else (DELETE, CREATE, SEARCH, ALL, ...) is not something a directional
  // view/edit-only portal relationship can grant — fall through to deny-all.
  if ($type === CRM_Core_Permission::VIEW) {
    $isPermCondition = 'IN (' . CRM_Contact_BAO_Relationship::EDIT . ',' . CRM_Contact_BAO_Relationship::VIEW . ')';
  }
  elseif ($type === CRM_Core_Permission::EDIT) {
    $isPermCondition = '= ' . CRM_Contact_BAO_Relationship::EDIT;
  }
  else {
    return;
  }

  $portalTypeId = _boosterportal_portal_parent_relationship_type_id();
  if (!$portalTypeId) {
    // RelationshipType not installed (e.g. mid-install) — grant nothing rather
    // than guess.
    return;
  }

  $relationshipClause = "contact_a.id IN (
    SELECT r.contact_id_b FROM civicrm_relationship r
    WHERE r.contact_id_a = {$contactID}
      AND r.relationship_type_id = {$portalTypeId}
      AND r.is_active = 1
      AND r.is_permission_a_b {$isPermCondition}
      AND (r.start_date IS NULL OR r.start_date <= CURDATE())
      AND (r.end_date IS NULL OR r.end_date >= CURDATE())
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
 * this, Booster_QBO_Student is simply absent from getFields() for a portal
 * parent and Api4 rejects any select of its fields as unknown ("Invalid field"),
 * even for the parent's own student.
 *
 * Only Booster_QBO_Student (least privilege, M9/M10): Booster_QBO lives on the
 * Household, and parents have no permissioned edge to the Household at all — the
 * aclWhereClause hook above only ever grants contact_id_b of a Portal_Parent_of
 * edge, and households are never contact_id_b of one. Granting the Household
 * group here would be dead weight (parents can't reach a Household row to read
 * the field on regardless) and would need its own design decision if household
 * visibility is ever wanted, so it is deliberately left out.
 *
 * This grant only unlocks the custom *field definitions*; it does not by itself
 * expose another family's data. Row-level privacy is enforced separately by
 * hook_civicrm_aclWhereClause() above, which gates which student *contacts* (and
 * therefore which field values) are reachable in the first place — this hook's
 * safety depends entirely on that gating being correct.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_aclGroup/
 */
function boosterportal_civicrm_aclGroup(int $type, ?int $contactID, string $tableName, array &$allGroups, array &$currentGroups): void {
  if ($tableName !== 'civicrm_custom_group' || empty($contactID) || $type !== CRM_ACL_API::VIEW) {
    return;
  }
  $groupIds = (array) CRM_Core_DAO::executeQuery(
    "SELECT id FROM civicrm_custom_group WHERE name = 'Booster_QBO_Student'"
  )->fetchMap('id', 'id');
  $currentGroups = array_unique(array_merge($currentGroups, $groupIds));
}

/**
 * Implements hook_civicrm_post().
 *
 * Security review C1 (critical): civicrm_acl_contact_cache is a snapshot table.
 * CiviCRM only truncates/rebuilds it opportunistically — typically triggered by
 * an unrelated contact write — never on a Relationship write. Without this hook,
 * disabling or deleting a Portal_Parent_of edge leaves the former parent with
 * full bulk visibility of the former child (everything gated by
 * hook_civicrm_aclWhereClause() above) until something else happens to flush the
 * cache, which could be arbitrarily long on a quiet site.
 *
 * Invalidate the cache entry for every contact on either end of the relationship
 * that changed (the "user_id" column of civicrm_acl_contact_cache is the viewer,
 * so this covers the parent; the student side is a no-op today since students
 * never authenticate, but is included in case that ever changes) and clear the
 * same-request "already built" short-circuit in
 * CRM_Contact_BAO_Contact_Permission::cache() so a subsequent read within this
 * same request rebuilds rather than trusting a now-stale flag.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_post/
 */
function boosterportal_civicrm_post(string $op, ?string $objectName, ?int $objectId, &$objectRef): void {
  if ($objectName !== 'Relationship') {
    return;
  }

  $contactIds = [];
  if ($objectRef && isset($objectRef->contact_id_a, $objectRef->contact_id_b)) {
    $contactIds = [(int) $objectRef->contact_id_a, (int) $objectRef->contact_id_b];
  }
  elseif ($objectId) {
    // Defensive fallback for a caller that passes a bare/partial $objectRef.
    // On 'delete' this only finds a row if something else in the same request
    // hasn't already removed it — CiviCRM core's own
    // CRM_Contact_BAO_Relationship::self_hook_civicrm_post() relies on
    // $objectRef still being populated on delete, so in practice the branch
    // above is expected to handle deletes too.
    $row = CRM_Core_DAO::executeQuery(
      'SELECT contact_id_a, contact_id_b FROM civicrm_relationship WHERE id = %1',
      [1 => [$objectId, 'Positive']]
    )->fetchAll();
    if ($row) {
      $contactIds = [(int) $row[0]['contact_id_a'], (int) $row[0]['contact_id_b']];
    }
  }

  foreach (array_unique(array_filter($contactIds)) as $contactId) {
    CRM_ACL_BAO_Cache::deleteContactCacheEntry($contactId);
  }
  unset(Civi::$statics['CRM_Contact_BAO_Contact_Permission']['processed']);
}

/**
 * Implements hook_civicrm_pre().
 *
 * Belt-and-braces guard (security review, following C2): a permission bit
 * (is_permission_a_b / is_permission_b_a) ticked on any relationship type other
 * than Portal_Parent_of would be honoured by CiviCRM core's own single-record
 * CRM_Contact_BAO_Contact_Permission::allow()/allowList()/relationshipList()
 * path regardless of anything in this extension — those are core mechanisms,
 * not something the aclWhereClause scoping above can protect. Refuse the write
 * outright instead, the same class of mistake the security review demonstrated
 * with a stock 'Child of' relationship type.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_pre/
 * @throws \CRM_Core_Exception
 */
function boosterportal_civicrm_pre(string $op, ?string $objectName, ?int $id, &$params): void {
  if ($objectName !== 'Relationship' || !in_array($op, ['create', 'edit'], TRUE)) {
    return;
  }

  $settingPermission = FALSE;
  foreach (['is_permission_a_b', 'is_permission_b_a'] as $field) {
    if (!empty($params[$field]) && (int) $params[$field] !== CRM_Contact_BAO_Relationship::NONE) {
      $settingPermission = TRUE;
      break;
    }
  }
  if (!$settingPermission) {
    return;
  }

  $typeId = $params['relationship_type_id'] ?? NULL;
  if (!$typeId && $id) {
    $typeId = CRM_Core_DAO::singleValueQuery(
      'SELECT relationship_type_id FROM civicrm_relationship WHERE id = %1',
      [1 => [$id, 'Positive']]
    );
  }

  $portalTypeId = _boosterportal_portal_parent_relationship_type_id();
  if (!$typeId || (int) $typeId !== $portalTypeId) {
    throw new CRM_Core_Exception(
      'boosterportal: refusing to set a permissioned relationship (is_permission_a_b/is_permission_b_a) ' .
      "on relationship type id " . var_export($typeId, TRUE) . '. Permissioned relationships are reserved ' .
      'for Portal_Parent_of — this protects the parent-portal ACL model from being silently extended to an ' .
      'unrelated relationship type (e.g. a mis-ticked permission box on a stock type like "Child of").'
    );
  }
}
