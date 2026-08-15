<?php
// QBO connection settings. Secrets (client_secret, refresh_token) are stored
// via Civi's credential vault: define CIVICRM_CRED_KEYS in civicrm.settings.php
// so settings named *_token/*_secret are encrypted at rest (runbooks/qbo-oauth.md).
$base = ['group' => 'boosterportal', 'is_domain' => 1, 'is_contact' => 0, 'add' => '1.0'];
return [
  'boosterportal_qbo_env' => $base + ['name' => 'boosterportal_qbo_env', 'type' => 'String', 'default' => 'sandbox', 'title' => 'QBO environment (sandbox|production)'],
  'boosterportal_qbo_client_id' => $base + ['name' => 'boosterportal_qbo_client_id', 'type' => 'String', 'default' => '', 'title' => 'QBO OAuth client id'],
  'boosterportal_qbo_client_secret' => $base + ['name' => 'boosterportal_qbo_client_secret', 'type' => 'String', 'default' => '', 'title' => 'QBO OAuth client secret'],
  'boosterportal_qbo_realm_id' => $base + ['name' => 'boosterportal_qbo_realm_id', 'type' => 'String', 'default' => '', 'title' => 'QBO company (realm) id'],
  'boosterportal_qbo_refresh_token' => $base + ['name' => 'boosterportal_qbo_refresh_token', 'type' => 'String', 'default' => '', 'title' => 'QBO OAuth refresh token'],
  // Task 13 (§6): where CRITICAL reconciliation findings get emailed.
  // Empty by default — Recon::emailCritical() skips sending silently when
  // this is unset, rather than erroring or emailing nobody.
  'boosterportal_webmaster_email' => $base + ['name' => 'boosterportal_webmaster_email', 'type' => 'String', 'default' => '', 'title' => 'Webmaster email (CRITICAL reconciliation alerts)'],
  // Task 13 (§6), check 9: CMS roles that mean "this login is not a band
  // parent". A board member or volunteer signs in through Entra SSO with an
  // account of their own, unconnected to any Portal_Parent_of relationship, so
  // "has a login and no students" is simply true of them and always will be.
  // Without this, check 9 reports every staff account as a broken parent on
  // every run - an ERROR nobody can ever clear, which is how a webmaster
  // learns to skim past the reconciliation email.
  //
  // A list of roles to EXCLUDE rather than one role to include, deliberately.
  // Forgetting to add a newly created role here produces a visible false
  // positive that can be fixed; the inclusive form would instead stop
  // examining a real parent whose account had drifted, and say nothing at all.
  // Noisy when stale beats silent when stale for anything that is meant to be
  // an alarm.
  //
  // A setting rather than a constant because the granular admin-side roles are
  // still to come: adding one should be an edit here, not a code change by
  // whoever happens to be maintaining Recon.php that year.
  'boosterportal_non_portal_roles' => $base + ['name' => 'boosterportal_non_portal_roles', 'type' => 'Array', 'default' => ['administrator', 'editor', 'author', 'contributor', 'booster_volunteer'], 'title' => 'CMS roles that are not band parents (excluded from reconciliation check 9)'],
];
