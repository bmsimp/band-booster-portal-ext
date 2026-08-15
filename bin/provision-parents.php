<?php
/**
 * Provision a WordPress account for every link-eligible parent contact.
 *
 * Run it with cv, from the site root:
 *
 *   cv scr wp-content/uploads/civicrm/ext/boosterportal/bin/provision-parents.php
 *
 * This is the WordPress replacement for the Drupal provisioning script
 * (design §4.4). It is idempotent — a parent who already has a UFMatch row is
 * skipped — so it is safe to re-run after every import, which is exactly how
 * it is meant to be used: new family imported, script run, that parent can
 * request a link.
 *
 * WHAT A PARENT ACCOUNT IS
 *
 *  - role 'parent' and nothing else (registered by the site's
 *    boosterportal-parent-role mu-plugin, which also blocks password reset);
 *  - a random password nobody records, on purpose. Parents sign in by magic
 *    link. There is no password to leak, phish, or reuse, and the reset door
 *    is shut, so the password is unusable by construction rather than by
 *    policy;
 *  - a UFMatch row tying the WordPress user id to the CiviCRM contact id for
 *    this domain. That row is what
 *    CRM_Boosterportal_Page_PortalLogin::runLogin() looks up after redeeming a
 *    token: no UFMatch, no session.
 *
 * STUDENTS ARE NEVER PROVISIONED. The eligibility query below only ever
 * matches a contact holding an OUTGOING Portal_Parent_of edge — a student
 * holds the incoming side and can never match it. That is the second of the
 * project's two non-negotiable rules, and it is enforced here by the shape of
 * the query rather than by a filter someone could edit out.
 */

// The eligibility rule is deliberately the SAME one
// Civi\Boosterportal\MagicLink::eligibleContactIdFor() applies at issue time:
// an Individual, not deleted, holding an outgoing (contact_id_a), active,
// currently-in-window Portal_Parent_of relationship whose is_permission_a_b is
// VIEW or EDIT. It is repeated here rather than shared because MagicLink's
// copy is private to a pre-auth class whose surface should not grow for the
// convenience of a console script.
//
// If the two ever drift, the failure is closed in both directions rather than
// open: MagicLink remains the sole authority on who may be issued a link, so a
// contact this script provisions but MagicLink rejects simply owns an account
// that can never be signed into, and a contact MagicLink accepts but this
// script missed gets "no portal account exists yet" and a webmaster ticket.
// Neither leaks anything. Reconciliation check #5 (Task 13) reports the second
// case.
/**
 * A WordPress login name derived from the address, guaranteed unique.
 *
 * The login is an internal handle here — parents never type it, because they
 * never type a password either — so it only has to be stable, legal, and not
 * already taken. sanitize_user() strips what WordPress will not accept; the
 * suffix loop handles two families whose addresses sanitize to the same
 * string.
 *
 * A closure rather than a named function because cv runs this file inside a
 * function scope: a named declaration would not exist until execution reached
 * it, which is after the loop that calls it.
 */
$uniqueLogin = function (string $email): string {
  $base = sanitize_user($email, TRUE);
  if ($base === '') {
    $base = 'parent';
  }
  $login = $base;
  $n = 1;
  while (username_exists($login)) {
    $login = $base . '-' . (++$n);
  }
  return $login;
};

$portalTypeId = _boosterportal_portal_parent_relationship_type_id();
if (!$portalTypeId) {
  echo "Portal_Parent_of relationship type is missing — is the extension installed?\n";
  return;
}

$dao = CRM_Core_DAO::executeQuery(
  'SELECT c.id AS contact_id, c.display_name, e.email
   FROM civicrm_contact c
   INNER JOIN civicrm_email e ON e.contact_id = c.id AND e.is_primary = 1
   WHERE c.contact_type = %1
     AND c.is_deleted = 0
     AND e.email IS NOT NULL AND e.email <> ""
     AND EXISTS (
       SELECT 1 FROM civicrm_relationship r
       WHERE r.contact_id_a = c.id
         AND r.relationship_type_id = %2
         AND r.is_active = 1
         AND r.is_permission_a_b IN (%3, %4)
         AND (r.start_date IS NULL OR r.start_date <= CURDATE())
         AND (r.end_date IS NULL OR r.end_date >= CURDATE())
     )
   ORDER BY c.id ASC',
  [
    1 => ['Individual', 'String'],
    2 => [$portalTypeId, 'Positive'],
    3 => [CRM_Contact_BAO_Relationship::EDIT, 'Positive'],
    4 => [CRM_Contact_BAO_Relationship::VIEW, 'Positive'],
  ]);

$domainId = CRM_Core_Config::domainID();
$created = $skipped = $problems = 0;

while ($dao->fetch()) {
  $contactId = (int) $dao->contact_id;
  $email = $dao->email;

  $existing = \Civi\Api4\UFMatch::get(FALSE)
    ->addWhere('contact_id', '=', $contactId)
    ->addWhere('domain_id', '=', $domainId)
    ->execute()->first();
  if ($existing) {
    $skipped++;
    continue;
  }

  // An account may already exist for this address without a UFMatch row —
  // a half-finished earlier run, or an account made by hand. Adopt it rather
  // than failing, but never silently change what it is: if it is not a plain
  // parent account, say so and move on. Handing an existing administrator
  // account a UFMatch row would wire a privileged account to a parent
  // contact, which is precisely what isSafeParentUser() exists to refuse.
  $wpUser = get_user_by('email', $email);
  if ($wpUser) {
    if ($wpUser->roles !== [CRM_Boosterportal_Page_PortalLogin::PARENT_ROLE]) {
      echo "SKIPPED contact {$contactId} ({$email}): a WordPress account already exists for that "
        . "address with roles [" . implode(', ', (array) $wpUser->roles) . "] — not a plain parent "
        . "account, so it was left alone. Resolve by hand.\n";
      $problems++;
      continue;
    }
    $uid = (int) $wpUser->ID;
  }
  else {
    $login = $uniqueLogin($email);
    $uid = wp_insert_user([
      'user_login' => $login,
      'user_email' => $email,
      // Long, random, and never recorded anywhere: parents sign in by magic
      // link, and the mu-plugin blocks password reset, so this value is only
      // ever a placeholder that makes password auth impossible.
      'user_pass' => wp_generate_password(64, TRUE, TRUE),
      'display_name' => $dao->display_name ?: $email,
      'nickname' => $dao->display_name ?: $email,
      'role' => CRM_Boosterportal_Page_PortalLogin::PARENT_ROLE,
      'show_admin_bar_front' => 'false',
    ]);
    if (is_wp_error($uid)) {
      echo "FAILED contact {$contactId} ({$email}): " . $uid->get_error_message() . "\n";
      $problems++;
      continue;
    }
    $uid = (int) $uid;
  }

  // CiviCRM's own WordPress integration hooks user_register and may have
  // created the UFMatch row already, by matching on email. Re-read before
  // writing: creating a second row for the same uf_id would be a duplicate,
  // and a row pointing at a DIFFERENT contact means CiviCRM matched this
  // address to somebody else — which must be looked at by a human, not
  // papered over here.
  $afterInsert = \Civi\Api4\UFMatch::get(FALSE)
    ->addWhere('uf_id', '=', $uid)
    ->addWhere('domain_id', '=', $domainId)
    ->execute()->first();
  if ($afterInsert) {
    if ((int) $afterInsert['contact_id'] === $contactId) {
      echo "OK contact {$contactId} ({$email}) -> wp user {$uid} (UFMatch created by CiviCRM's own sync)\n";
      $created++;
    }
    else {
      echo "PROBLEM contact {$contactId} ({$email}): wp user {$uid} is already matched to contact "
        . $afterInsert['contact_id'] . ". Left as-is — investigate before this parent signs in.\n";
      $problems++;
    }
    continue;
  }

  \Civi\Api4\UFMatch::create(FALSE)
    ->addValue('uf_id', $uid)
    ->addValue('uf_name', $email)
    ->addValue('contact_id', $contactId)
    ->addValue('domain_id', $domainId)
    ->execute();
  echo "OK contact {$contactId} ({$email}) -> wp user {$uid}\n";
  $created++;
}

echo "\nprovisioned: {$created}, already had an account: {$skipped}, needing attention: {$problems}\n";
