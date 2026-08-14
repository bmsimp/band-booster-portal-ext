<?php
use QuickBooksOnline\API\DataService\DataService;

/**
 * Admin page: shows connection status; "Connect" redirects to Intuit consent;
 * Intuit redirects back here with ?code=&realmId=&state= and we store the
 * tokens. Documented for the next webmaster in runbooks/qbo-oauth.md.
 *
 * CSRF/state handling (Task 9 controller amendment): the SDK's
 * OAuth2LoginHelper auto-generates a "state" value if none is supplied, and
 * DataService::Configure()'s getAuthorizationCodeURL() does include it in
 * the outbound URL to Intuit — but the SDK never validates state on the
 * return leg (exchangeAuthorizationCodeForToken() ignores $_GET['state']
 * entirely; verified by reading OAuth2LoginHelper.php). Left alone, the SDK
 * offers zero CSRF protection despite the parameter's presence. So the
 * state check here is fully manual: generate a random value in the connect
 * step, store it server-side in the admin's CiviCRM session, and require it
 * to match (via hash_equals(), one-time-use) before exchanging the code.
 */
class CRM_Boosterportal_Page_QboOAuth extends CRM_Core_Page {

  private const SESSION_STATE_KEY = 'boosterportal_qbo_oauth_state';

  public function run() {
    $session = CRM_Core_Session::singleton();
    $redirect = CRM_Utils_System::url('civicrm/admin/boosterportal/qbo', NULL, TRUE, NULL, FALSE, FALSE, TRUE);

    // Callback leg from Intuit. Validate state before touching the code at all.
    if (!empty($_GET['code']) && !empty($_GET['realmId'])) {
      $expectedState = $session->get(self::SESSION_STATE_KEY);
      // One-time use: clear immediately so a replayed/reused callback URL
      // (e.g. from browser history or a shared link) can never pass again.
      $session->set(self::SESSION_STATE_KEY, NULL);
      if (empty($_GET['state']) || !is_string($expectedState) || $expectedState === ''
        || !hash_equals($expectedState, (string) $_GET['state'])) {
        CRM_Core_Session::setStatus(
          ts('QuickBooks connection failed: the request could not be verified (state mismatch). Please try Connect again.'),
          ts('Booster Portal'), 'error');
        CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/admin/boosterportal/qbo'));
      }

      $ds = DataService::Configure([
        'auth_mode' => 'oauth2',
        'ClientID' => Civi::settings()->get('boosterportal_qbo_client_id'),
        'ClientSecret' => Civi::settings()->get('boosterportal_qbo_client_secret'),
        'RedirectURI' => $redirect,
        'scope' => 'com.intuit.quickbooks.accounting',
        'baseUrl' => Civi::settings()->get('boosterportal_qbo_env') === 'production' ? 'Production' : 'Development',
      ]);
      $helper = $ds->getOAuth2LoginHelper();
      $token = $helper->exchangeAuthorizationCodeForToken($_GET['code'], $_GET['realmId']);
      Civi::settings()->set('boosterportal_qbo_refresh_token', $token->getRefreshToken());
      Civi::settings()->set('boosterportal_qbo_realm_id', $_GET['realmId']);
      CRM_Core_Session::setStatus(ts('QuickBooks connected.'), ts('Booster Portal'), 'success');
      CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/admin/boosterportal/qbo'));
    }

    // Connect (or reconnect) leg: mint a fresh state, stash it, redirect to Intuit.
    if (!empty($_GET['connect'])) {
      $state = bin2hex(random_bytes(16));
      $session->set(self::SESSION_STATE_KEY, $state);
      $ds = DataService::Configure([
        'auth_mode' => 'oauth2',
        'ClientID' => Civi::settings()->get('boosterportal_qbo_client_id'),
        'ClientSecret' => Civi::settings()->get('boosterportal_qbo_client_secret'),
        'RedirectURI' => $redirect,
        'scope' => 'com.intuit.quickbooks.accounting',
        'state' => $state,
        'baseUrl' => Civi::settings()->get('boosterportal_qbo_env') === 'production' ? 'Production' : 'Development',
      ]);
      $helper = $ds->getOAuth2LoginHelper();
      CRM_Utils_System::redirect($helper->getAuthorizationCodeURL());
    }

    $connected = (bool) Civi::settings()->get('boosterportal_qbo_refresh_token');
    $this->assign('connected', $connected);
    $this->assign('realmId', Civi::settings()->get('boosterportal_qbo_realm_id'));
    $this->assign('env', Civi::settings()->get('boosterportal_qbo_env'));
    parent::run();
  }

}
