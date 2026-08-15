<?php
use Civi\Boosterportal\MagicLink;

/**
 * /civicrm/portal/request-link : email form (GET) + send (POST).
 * /civicrm/portal/login?t=...  : redeem, start CMS session, go to dashboard.
 *
 * Both routes are *always allow* (xml/Menu/boosterportal.xml) by
 * construction — this page IS the pre-auth front door, same as MagicLink.php
 * itself (see InvariantTest's allowlist entry there).
 *
 * AMENDMENT (Task 15 review, POST/CSRF): the plan's original draft gated the
 * send action on nothing but "$_POST['email'] is non-empty", which is
 * POST-only by construction (a GET request never populates $_POST) but
 * carries no CSRF protection at all — a third-party page could
 * auto-submit a hidden form to this endpoint from a victim's browser.
 * Because the target address is attacker-supplied in the POST body (not
 * derived from the victim's session/identity), a forged request can't be
 * used to send a link to somebody else's inbox that the attacker couldn't
 * already reach directly — but it CAN be used to burn a real requester's
 * rate-limit budget (3/hour/address, keyed by email — not by IP/session) by
 * driving traffic through unwitting browsers, or simply to spam-click this
 * endpoint attributed to someone else's IP in logs. A lightweight
 * session-bound one-time nonce (the same hand-rolled pattern already used
 * for the QBO OAuth 'state' parameter in QboOAuth.php, since this Page has
 * no quickform to hand a qfKey from) closes that: the form load mints a
 * token and stashes it in the (anonymous-safe, PHP-session-backed)
 * CRM_Core_Session; the POST must echo it back via hash_equals() before
 * sendLink() is ever called. A missing/mismatched token is treated as if no
 * submission happened at all (fresh form re-rendered, no email sent, no
 * distinguishing error) rather than surfaced as a CSRF failure message —
 * consistent with this page's anti-enumeration design (never give an
 * observer more signal than "here is the form").
 *
 * ADVERSARIAL SECURITY REVIEW (post-launch, Task 15 follow-up):
 *
 *  IMPORTANT-2(ii): before finalizing a session, the loaded WordPress user
 *  must carry ONLY the parent role AND hold no capability above an ordinary
 *  parent's. A contact who is both a parent AND a board member (or any
 *  other elevated role) must never get a PRIVILEGED session merely by
 *  walking the magic-link door — that door was designed and reviewed for
 *  the parent role only. WordPress has no uid-1 concept the way Drupal
 *  does; the equivalent guard is the capability check below. See
 *  isSafeParentUser() and hasElevatedCapability().
 *
 *  IMPORTANT-4: Referrer-Policy: no-referrer is set on every response from
 *  this page. civicrm/portal/login?t=... carries the (already single-use,
 *  now-consumed-on-redemption) raw token in its own URL — without this
 *  header, navigating away from that URL to any third-party resource (an
 *  external link, a remotely-hosted image, etc.) would leak the full URL,
 *  token included, to that third party via the Referer request header. The
 *  token dies on first redemption regardless (IMPORTANT-1), which already
 *  shrinks this to a narrow window, but the header costs nothing and closes
 *  it further. runbooks/magic-links.md documents the residual (access logs
 *  / any CDN or proxy in front of this site, e.g. Cloudflare, still see the
 *  full URL server-side — this header only stops the BROWSER from handing
 *  it to a third party on subsequent navigation).
 */
class CRM_Boosterportal_Page_PortalLogin extends CRM_Core_Page {

  private const SESSION_CSRF_KEY = 'boosterportal_request_link_csrf';

  /**
   * The one WordPress role a portal parent holds.
   *
   * Registered by the site's boosterportal-parent-role mu-plugin (which also
   * blocks password reset for it) and assigned by bin/provision-parents.php,
   * both of which refer to this constant rather than repeating the string.
   */
  public const PARENT_ROLE = 'parent';

  /**
   * Capabilities that put an account above an ordinary parent.
   *
   * Grouped by what each one actually gives away:
   *
   *  - manage_options / install_plugins / activate_plugins / edit_plugins /
   *    switch_themes / edit_theme_options / update_core / import / export
   *                                                  : site administration.
   *  - edit_users / create_users / delete_users /
   *    promote_users / list_users / remove_users     : can reach or become
   *                                                    another account.
   *  - edit_posts / edit_pages / publish_posts /
   *    edit_others_posts / upload_files              : any content role,
   *                                                    contributor upward.
   *  - unfiltered_html                               : can inject markup.
   *  - administrator / super admin                   : a WordPress role name
   *    is itself testable as a capability, and these two are exactly what
   *    CRM_Core_Permission_WordPress::check() looks for before handing an
   *    account EVERY CiviCRM permission unconditionally. Checking the same
   *    two here means this door refuses precisely the accounts CiviCRM
   *    itself would treat as omnipotent.
   *  - view_all_contacts / edit_all_contacts /
   *    access_all_custom_data / administer_civicrm /
   *    administer_civicrm_system / administer_civicrm_data /
   *    access_deleted_contacts                       : CiviCRM's own
   *    permissions, exposed by its WordPress integration as capabilities
   *    (permission string lowercased, spaces to underscores). Each one
   *    defeats this project's per-family ACL outright — an account holding
   *    view_all_contacts sees every family, hooks or no hooks — so none may
   *    ever come through this door.
   *
   * NOT on this list, deliberately: access_civicrm. That is the base
   * permission the parent dashboard Afform itself requires
   * (ang/afformPortalDashboard.aff.json, "permission": "access CiviCRM"), so
   * every provisioned parent holds it by design. It grants entry to CiviCRM,
   * not visibility of anybody's data: what a parent can then see is decided
   * entirely by boosterportal.php's ACL hooks.
   *
   * Deliberately a denylist of specific capabilities rather than "holds any
   * capability besides read and access_civicrm": WordPress plugins add
   * capabilities of their own freely (an events plugin, a forms plugin), and a
   * parent who picks up some harmless third-party capability must not be
   * locked out of the portal. The design §4.3 invariant that content-editor
   * roles hold zero CiviCRM capabilities backstops the other direction, and
   * the site's boosterportal-civicrm-capabilities mu-plugin is what keeps that
   * invariant true against CiviCRM's own installer.
   */
  private const ELEVATED_CAPABILITIES = [
    'administrator',
    'super admin',
    'manage_options',
    'install_plugins',
    'activate_plugins',
    'edit_plugins',
    'switch_themes',
    'edit_themes',
    'edit_theme_options',
    'update_core',
    'import',
    'export',
    'edit_users',
    'create_users',
    'delete_users',
    'promote_users',
    'list_users',
    'remove_users',
    'edit_posts',
    'edit_pages',
    'publish_posts',
    'edit_others_posts',
    'upload_files',
    'moderate_comments',
    'unfiltered_html',
    'administer_civicrm',
    'administer_civicrm_system',
    'administer_civicrm_data',
    'view_all_contacts',
    'edit_all_contacts',
    'access_all_custom_data',
    'access_deleted_contacts',
  ];

  public function run() {
    // IMPORTANT-4 — see class docblock. Guarded for the (non-HTTP) test/CLI
    // context, where headers_sent() reads TRUE (or emitting one would just
    // warn) and there is no browser on the other end to protect anyway.
    if (!headers_sent() && php_sapi_name() !== 'cli') {
      header('Referrer-Policy: no-referrer');
    }

    $path = CRM_Utils_System::currentPath();

    if ($path === 'civicrm/portal/login') {
      $this->runLogin();
      return;
    }

    $this->runRequestLink();
  }

  private function runLogin(): void {
    try {
      $cid = (new MagicLink())->redeem($_GET['t'] ?? '');
    }
    catch (CRM_Core_Exception $e) {
      CRM_Core_Session::setStatus(ts('That link is invalid or has expired. Request a new one.'), ts('Sign in'), 'error');
      CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/portal/request-link'));
      return;
    }

    // Log into the CMS account provisioned for this contact (UFMatch).
    // MINOR-2: scope to this CiviCRM domain explicitly — UFMatch is a
    // multi-domain table (civicrm_uf_match.domain_id) and every other
    // lookup against it in CiviCRM core itself (CRM_Core_BAO_UFMatch) scopes
    // by domain the same way; a bare contact_id match would be wrong on any
    // multi-domain install even though this site only ever has one domain.
    $ufMatch = \Civi\Api4\UFMatch::get(FALSE)
      ->addWhere('contact_id', '=', $cid)
      ->addWhere('domain_id', '=', CRM_Core_Config::domainID())
      ->execute()->first();
    if (!$ufMatch) {
      CRM_Core_Session::setStatus(ts('No portal account exists for this address yet. Contact the boosters.'), ts('Sign in'), 'error');
      CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/portal/request-link'));
      return;
    }

    $uid = (int) $ufMatch['uf_id'];
    $user = get_userdata($uid);
    if (!$user || !self::isSafeParentUser(self::rolesOf($user), self::hasElevatedCapability($uid))) {
      // Deliberately the SAME message/redirect as "no account" and as the
      // token-invalid branch above — this must never tell an observer
      // WHICH reason a login failed for (unknown token vs. no account vs.
      // "this account isn't allowed to use this door").
      CRM_Core_Session::setStatus(ts('No portal account exists for this address yet. Contact the boosters.'), ts('Sign in'), 'error');
      CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/portal/request-link'));
      return;
    }

    // The WordPress equivalent of Drupal's user_login_finalize(): put the auth
    // cookie on the response, make the account current for the rest of this
    // request, and fire the action other plugins hook to observe a login.
    // $remember = FALSE deliberately — a session cookie, not a fortnight-long
    // one; a parent who wants back in requests another link. The cookie is a
    // header, and the redirect below is the last chance to send one, so it
    // goes first.
    wp_set_auth_cookie($uid, FALSE);
    wp_set_current_user($uid);
    do_action('wp_login', $user->user_login, $user);

    CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/portal'));
  }

  /**
   * The role list of a WordPress user, defensively.
   *
   * WP_User::$roles is documented as a list of role-name strings, but it is
   * reconstructed from user meta — unserialized data — so nothing structural
   * guarantees its shape. Whatever comes back is handed to isSafeParentUser()
   * as-is; that function answers "no" to anything unexpected rather than
   * trusting this.
   *
   * @param \WP_User $user
   * @return array
   */
  private static function rolesOf($user): array {
    return is_array($user->roles ?? NULL) ? $user->roles : [];
  }

  /**
   * IMPORTANT-2(ii), WordPress half: does this account hold any capability
   * that puts it above an ordinary parent?
   *
   * Kept separate from isSafeParentUser() (and not unit-tested) because it is
   * the one part of the gate that must call WordPress itself — user_can() and
   * is_super_admin() do not exist under the extension's headless PHPUnit
   * bootstrap. It is deliberately thin: a loop, no logic. The decision it
   * feeds lives in isSafeParentUser(), which is pure and fully tested.
   *
   * is_super_admin() is checked first and separately: on a multisite network a
   * super admin's capabilities are granted dynamically, and while user_can()
   * does honour that, the explicit check documents the case rather than
   * leaving it to be inferred.
   */
  private static function hasElevatedCapability(int $uid): bool {
    if (function_exists('is_super_admin') && is_super_admin($uid)) {
      return TRUE;
    }
    foreach (self::ELEVATED_CAPABILITIES as $capability) {
      if (user_can($uid, $capability)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * IMPORTANT-2(ii): TRUE only for a WordPress account that is safe to sign a
   * session in as via a magic link — an ordinary parent and nothing more.
   *
   * Reject:
   *  - any account holding an elevated capability (see ELEVATED_CAPABILITIES
   *    above). This is the WordPress equivalent of Drupal's uid-1 guard:
   *    WordPress has no superuser id, so "is this account privileged" is a
   *    question about capabilities rather than about a magic number;
   *  - any account whose role list is anything OTHER than exactly ['parent'] —
   *    in particular, a person who is both a provisioned parent AND separately
   *    an administrator or editor must not receive session privileges beyond
   *    "parent" just because they walked in through the magic-link door
   *    instead of Entra SSO (Task 14).
   *
   * Public + static, and pure, so it is independently testable: the headless
   * PHPUnit bootstrap boots CiviCRM's classloader only, with no WordPress at
   * all, so nothing here may call get_userdata(), user_can() or any other
   * WordPress function. The caller does that (see runLogin() and
   * hasElevatedCapability()) and passes plain values in.
   *
   * @param string[] $roles
   *   Ordinarily WP_User::$roles. Anything that is not a list of strings is
   *   rejected rather than interpreted.
   * @param bool $hasElevatedCapability
   *   The answer hasElevatedCapability() gave for this account.
   */
  public static function isSafeParentUser(array $roles, bool $hasElevatedCapability): bool {
    if ($hasElevatedCapability) {
      return FALSE;
    }
    // Non-string entries are dropped rather than compared: $roles comes from
    // unserialized user meta, so a corrupted or tampered value can only ever
    // FAIL the exact-match test below, never satisfy it by accident.
    $roles = array_values(array_filter($roles, 'is_string'));
    sort($roles);
    return $roles === [self::PARENT_ROLE];
  }

  private function runRequestLink(): void {
    $session = CRM_Core_Session::singleton();
    $sent = FALSE;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['email'])) {
      $expected = $session->get(self::SESSION_CSRF_KEY);
      $submitted = $_POST['csrf'] ?? '';
      // One-time use regardless of outcome: a stale token should never
      // validate a second submission (replay), successful or not.
      $session->set(self::SESSION_CSRF_KEY, NULL);
      if (is_string($expected) && $expected !== '' && is_string($submitted)
        && hash_equals($expected, $submitted)) {
        (new MagicLink())->sendLink(trim($_POST['email']), CRM_Utils_System::ipAddress());
        $sent = TRUE;
        $this->assign('sent', TRUE);
      }
      // Invalid/missing token: fall through and re-render a fresh form,
      // exactly as if nothing had been submitted — no distinguishing error.
    }

    if (!$sent) {
      $token = bin2hex(random_bytes(16));
      $session->set(self::SESSION_CSRF_KEY, $token);
      $this->assign('csrf', $token);
    }
    parent::run();
  }

}
