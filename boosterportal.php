<?php
declare(strict_types = 1);

// phpcs:disable PSR1.Files.SideEffects
require_once 'boosterportal.civix.php';
// phpcs:enable

// This civix format (25.10.2) ships no "composer" mixin in the core mixin
// library (only setting-php/mgd-php/theme-php/entity-types-php exist), so
// the vendor/ autoloader for composer dependencies (quickbooks/v3-php-sdk,
// Task 9) is not wired automatically the way it is for extension classes
// under psr0/psr4. Load it here, guarded, since `composer install`/`require`
// may not have run yet (e.g. a fresh checkout before CI's install step).
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
  require_once __DIR__ . '/vendor/autoload.php';
}

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
 * Relationship type ids allowed to carry a non-NONE is_permission_a_b value,
 * memoized for the life of the request:
 *  - Portal_Parent_of: this extension's own portal ACL edge.
 *  - 'Employee of': CiviCRM core itself creates a permissioned Employee-of
 *    relationship in the "contribute on behalf of an organisation" flow
 *    (CRM_Contribute_Form_Contribution_Confirm, via Api4 Relationship::create()
 *    with is_permission_a_b:name = 'View and update'), wrapped in a try/catch
 *    that only tolerates a "Duplicate Relationship" CRM_Core_Exception and
 *    rethrows anything else — including one thrown by hook_civicrm_pre()
 *    below. Without this exemption the guard fatals the contribution
 *    confirmation page AFTER payment has already been captured (security
 *    review N1). CRM_Contact_BAO_RelationshipType::getEmployeeRelationshipTypeID()
 *    itself throws if the 'Employee of' type has been deleted, so the lookup
 *    is wrapped defensively and simply omitted from the allow-list in that case.
 *
 * @return int[]
 */
function _boosterportal_allowed_permissioned_relationship_type_ids(): array {
  if (!isset(Civi::$statics['boosterportal']['allowed_permissioned_relationship_type_ids'])) {
    $ids = [_boosterportal_portal_parent_relationship_type_id()];
    try {
      $ids[] = CRM_Contact_BAO_RelationshipType::getEmployeeRelationshipTypeID();
    }
    catch (CRM_Core_Exception $e) {
      // 'Employee of' relationship type missing/deleted — nothing to exempt.
    }
    Civi::$statics['boosterportal']['allowed_permissioned_relationship_type_ids'] = array_filter($ids);
  }
  return Civi::$statics['boosterportal']['allowed_permissioned_relationship_type_ids'];
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
 *    must not grant access. IMPORTANT (N5 correction — the previous version of
 *    this comment overclaimed): CURDATE() here is evaluated at ACL CACHE BUILD
 *    time (i.e. whenever CRM_Contact_BAO_Contact_Permission::cache() actually
 *    runs the build SQL for a given user), not at every read — bulk queries
 *    after that just JOIN against the already-populated civicrm_acl_contact_cache
 *    snapshot table and never re-run this SQL. So this filter correctly excludes
 *    an expired-but-still-is_active=1 relationship in any FRESH cache build (the
 *    first build for a user, or any rebuild triggered by hook_civicrm_post()
 *    below on a relationship write) — it does NOT retroactively fix an
 *    already-stale cache row from before expiry. The real bound on how long a
 *    stale row can persist is the "Disable expired relationships" scheduled job
 *    (Job.disable_expired_relationships, enabled on this dev site), which flips
 *    is_active off once end_date passes and, via hook_civicrm_post() below,
 *    invalidates the affected cache rows so the next read rebuilds fresh. THIS
 *    JOB MUST STAY ENABLED IN PRODUCTION — this date filter narrows the window
 *    before that job next runs, it is not a substitute for it. (Checked for a
 *    core job that rebuilds/flushes civicrm_acl_contact_cache itself on a
 *    schedule: Job.acl_cache_flush exists but calls CRM_ACL_BAO_Cache::resetCache(),
 *    which only touches the unrelated civicrm_acl_cache group-ACL table — not
 *    civicrm_acl_contact_cache — so it is not relevant here and was left disabled.)
 *  - See hook_civicrm_post() below for cache invalidation on relationship writes,
 *    hook_civicrm_pre() for the API-level guard against setting permission bits
 *    on any disallowed relationship type or direction, and
 *    hook_civicrm_validateForm() for the same rule as an inline UI form error.
 *  - $tables/$whereTables are deliberately left untouched (empty) — I6: a
 *    non-empty $whereTables is exactly what CRM_Core_Permission::giveMeAllACLs()
 *    treats as "this hook is granting something", so leaving them empty makes
 *    giveMeAllACLs() correctly return FALSE for a portal parent even though
 *    $whereClause itself grants some rows (fail-closed for any caller that only
 *    checks giveMeAllACLs() rather than actually running the query). Populating
 *    them would also inject our relationship subquery as an extra JOIN into
 *    unrelated queries, since $tables/$whereTables are interpreted as
 *    query-wide join requirements, not something scoped to $whereClause; if a
 *    specific screen misbehaves because of that omission, fix that screen, not
 *    this hook.
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
 * N6: parameters are deliberately untyped (no ?int/?string). This hook fires
 * for every entity write, system-wide, from every extension — a strict scalar
 * type here would TypeError on the first non-conforming value any weak-mode
 * core caller or other extension ever passes for ANY entity, which is a fatal
 * error inside an ACL-adjacent hook rather than a recoverable one. Check the
 * entity name first (loose, before touching $objectId at all), then cast
 * defensively only once we know we are looking at a Relationship.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_post/
 */
function boosterportal_civicrm_post($op, $objectName, $objectId, &$objectRef): void {
  if ($objectName !== 'Relationship') {
    return;
  }
  $objectId = $objectId !== NULL ? (int) $objectId : NULL;

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
 * ticked on any relationship type other than Portal_Parent_of (or core's own
 * Employee-of on-behalf-of-organisation flow, N1 — see
 * _boosterportal_allowed_permissioned_relationship_type_ids()) would be
 * honoured by CiviCRM core's own single-record
 * CRM_Contact_BAO_Contact_Permission::allow()/allowList()/relationshipList()
 * path regardless of anything in this extension — those are core mechanisms,
 * not something the aclWhereClause scoping above can protect. Refuse the
 * write outright instead, the same class of mistake the security review
 * demonstrated with a stock 'Child of' relationship type.
 *
 * N2: is_permission_b_a is rejected outright on EVERY relationship type,
 * including Portal_Parent_of itself. The portal edge is directional by design
 * (§4.4) — parent (a) may view student (b), never the reverse — and
 * CiviCRM core's relationshipList()/allow() honour is_permission_b_a exactly
 * the same way as is_permission_a_b, just in the opposite direction, so
 * setting it on Portal_Parent_of would let a student gain a direct-id view of
 * their own parent. The on-behalf-of core flow (N1) only ever sets
 * is_permission_a_b, so this is not expected to affect it (verified below).
 *
 * hook_civicrm_validateForm() below applies the same rule as an inline form
 * error for the one UI path that can reach it (CRM_Contact_Form_Relationship);
 * this hook remains the API-level backstop for every other caller.
 *
 * N6: parameters are deliberately untyped (no ?int/?string) — see the same
 * note on hook_civicrm_post() above; the reasoning is identical.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_pre/
 * @throws \CRM_Core_Exception
 */
function boosterportal_civicrm_pre($op, $objectName, $id, &$params): void {
  if ($objectName !== 'Relationship' || !in_array($op, ['create', 'edit'], TRUE)) {
    return;
  }
  $id = $id !== NULL ? (int) $id : NULL;

  if (!empty($params['is_permission_b_a']) && (int) $params['is_permission_b_a'] !== CRM_Contact_BAO_Relationship::NONE) {
    throw new CRM_Core_Exception(
      'boosterportal: refusing to set is_permission_b_a to a non-NONE value on any relationship. The portal ' .
      'ACL edge is directional (parent views student, never the reverse) — this direction is never used and ' .
      'must stay "None".'
    );
  }

  if (empty($params['is_permission_a_b']) || (int) $params['is_permission_a_b'] === CRM_Contact_BAO_Relationship::NONE) {
    return;
  }

  $typeId = $params['relationship_type_id'] ?? NULL;
  if (!$typeId && $id) {
    $typeId = CRM_Core_DAO::singleValueQuery(
      'SELECT relationship_type_id FROM civicrm_relationship WHERE id = %1',
      [1 => [$id, 'Positive']]
    );
  }

  $allowedTypeIds = _boosterportal_allowed_permissioned_relationship_type_ids();
  if (!$typeId || !in_array((int) $typeId, $allowedTypeIds, TRUE)) {
    throw new CRM_Core_Exception(
      'boosterportal: refusing to set is_permission_a_b on relationship type id ' . var_export($typeId, TRUE) . '. ' .
      'Permissioned relationships are reserved for Portal_Parent_of (and CiviCRM core\'s own Employee-of ' .
      'on-behalf-of-organisation flow) — this protects the parent-portal ACL model from being silently ' .
      'extended to an unrelated relationship type (e.g. a mis-ticked permission box on a stock type like "Child of").'
    );
  }
}

/**
 * Implements hook_civicrm_validateForm().
 *
 * N7: UI-level front door onto the same rule as the hook_civicrm_pre() guard
 * above, for the one form that can reach it interactively:
 * CRM_Contact_Form_Relationship. Without this, ticking a permission dropdown
 * on the wrong relationship type in the UI hits the pre-hook's
 * CRM_Core_Exception at save time — a white-screen fatal — instead of an
 * inline field error. The pre hook stays in place regardless: this is a
 * friendlier front door, not a replacement for the API-level backstop
 * (Relationship writes from anywhere other than this one form still go
 * through hook_civicrm_pre() only).
 *
 * $fields['relationship_type_id'] arrives here as CiviCRM's composite select
 * value ("<relationship_type_id>_a_b" or "..._b_a" — see
 * CRM_Contact_Form_Relationship::buildQuickForm()/postProcess(), which builds
 * and then parses the same format), not a bare integer.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_validateForm/
 */
function boosterportal_civicrm_validateForm($formName, &$fields, &$files, &$form, &$errors): void {
  if ($formName !== 'CRM_Contact_Form_Relationship') {
    return;
  }

  $permABSet = !empty($fields['is_permission_a_b']) && (int) $fields['is_permission_a_b'] !== CRM_Contact_BAO_Relationship::NONE;
  $permBASet = !empty($fields['is_permission_b_a']) && (int) $fields['is_permission_b_a'] !== CRM_Contact_BAO_Relationship::NONE;
  if (!$permABSet && !$permBASet) {
    return;
  }

  if ($permBASet) {
    $errors['is_permission_b_a'] = ts('The portal ACL edge is directional and never uses this direction. Set this back to "None".');
  }

  if ($permABSet) {
    $typeIdParts = explode('_', (string) ($fields['relationship_type_id'] ?? ''));
    $typeId = (int) ($typeIdParts[0] ?? 0);
    $allowedTypeIds = _boosterportal_allowed_permissioned_relationship_type_ids();
    if (!$typeId || !in_array($typeId, $allowedTypeIds, TRUE)) {
      $errors['is_permission_a_b'] = ts('Permissioned relationships (View/Edit access) are reserved for the "Portal Parent of" relationship type. Choose "None" here, or use "Portal Parent of" instead.');
    }
  }
}

/**
 * Implements hook_civicrm_pageRun().
 *
 * Task 17 (§4.9): loads the dashboard's two framework-free widgets --
 * js/booster-balance.js and js/booster-signout.js -- on the parent dashboard
 * route only. Verified empirically that this hook DOES fire
 * for an Afform server_route page: CRM_Afform_Page_AfformBase (the
 * page_callback afform.php's hook_civicrm_alterMenu() wires up for every
 * Afform with a server_route) extends CRM_Core_Page and calls parent::run()
 * at the end of its own run(), which is what invokes this hook — same as any
 * ordinary CRM_Core_Page. No afform-specific hook (e.g.
 * hook_civicrm_alterAngular) was needed; this ordinary page hook is
 * sufficient and is the smaller surface (it does not run for every Angular
 * page site-wide, only for actual CRM_Core_Page::run() calls, of which this
 * route's AfformBase page is one).
 *
 * Civi::resources()->addScriptFile() (not a raw <script> tag) so the file is
 * served through CiviCRM's normal asset pipeline (cache-busting query string,
 * correct MIME type, extension-relative path resolution).
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_pageRun/
 */
function boosterportal_civicrm_pageRun(&$page): void {
  if (CRM_Utils_System::currentPath() === 'civicrm/portal') {
    Civi::resources()->addScriptFile('boosterportal', 'js/booster-balance.js');
    Civi::resources()->addScriptFile('boosterportal', 'js/booster-signout.js');
  }
}
