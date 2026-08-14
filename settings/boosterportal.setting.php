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
];
