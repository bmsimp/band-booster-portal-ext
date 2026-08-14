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
 *  IMPORTANT-2(ii): before finalizing a session, the loaded Drupal user must
 *  carry ONLY the parent role (and must not be uid 1, Drupal's superuser,
 *  which bypasses permission checks regardless of its role list). A contact
 *  who is both a parent AND a board member (or any other elevated role)
 *  must never get a PRIVILEGED session merely by walking the magic-link
 *  door — that door was designed and reviewed for the parent role only.
 *  See isSafeParentUser().
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
    $user = \Drupal\user\Entity\User::load($ufMatch['uf_id']);
    if (!$user || !self::isSafeParentUser((int) $user->id(), $user->getRoles(TRUE))) {
      // Deliberately the SAME message/redirect as "no account" and as the
      // token-invalid branch above — this must never tell an observer
      // WHICH reason a login failed for (unknown token vs. no account vs.
      // "this account isn't allowed to use this door").
      CRM_Core_Session::setStatus(ts('No portal account exists for this address yet. Contact the boosters.'), ts('Sign in'), 'error');
      CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/portal/request-link'));
      return;
    }
    user_login_finalize($user);
    CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/portal'));
  }

  /**
   * IMPORTANT-2(ii): TRUE only for a Drupal user that is safe to sign in as
   * via a magic link — i.e. an ordinary parent account and nothing more.
   * Reject:
   *  - uid 1 (Drupal's superuser; bypasses every permission check regardless
   *    of its assigned role list, so it must never be reachable via this
   *    door under any circumstance);
   *  - any account whose role list (excluding the two implicit/locked
   *    anonymous/authenticated roles) is anything OTHER than exactly
   *    ['parent'] — in particular, a person who is both a provisioned
   *    parent AND (separately) a board_member/administrator must not
   *    receive session privileges beyond "parent" just because they walked
   *    in through the magic-link door instead of Entra SSO (Task 14).
   *
   * Public + static so this is independently testable. Deliberately takes
   * (int $uid, array $roles) rather than a \Drupal\user\UserInterface — this
   * was originally typed against that interface, but Task 17's warm-up
   * (unit-testing this function) found that \Drupal\user\UserInterface is
   * not autoloadable at all under this extension's headless PHPUnit
   * bootstrap (tests/phpunit/bootstrap.php only boots the CiviCRM
   * classloader level, not Drupal's own autoloader/service container — see
   * PortalLoginTest, which hit "Class or interface Drupal\user\UserInterface
   * does not exist" when trying to mock it), so a real interface-typed
   * parameter cannot be stubbed there at all, not even with a PHPUnit mock.
   * Accepting plain scalars/arrays instead makes this a pure function
   * testable with zero Drupal dependency, and keeps the caller (runLogin(),
   * above) the one place that still talks to the real UserInterface —
   * $user->id() and $user->getRoles(TRUE).
   *
   * VERIFIED (quality-review MINOR-6, re-checked against both Drupal core
   * source and a real account on this dev site): passing TRUE to
   * \Drupal\user\Entity\User::getRoles() means "exclude the locked roles" —
   * core's own implementation (web/core/modules/user/src/Entity/User.php)
   * only ever adds the implicit anonymous/authenticated role when
   * $exclude_locked_roles is FALSE, so a getRoles(TRUE) call NEVER returns
   * 'authenticated' in the first place. Reproduced live: a real
   * provisioned parent account's getRoles(TRUE) returned exactly
   * ["parent"]; only getRoles(FALSE)/getRoles() (no argument) returned
   * ["authenticated", "parent"]. So on the actual call path used by
   * runLogin() above, $roles here never contains 'authenticated' — there
   * was no bug to fix on that path.
   *
   * The filter below is added anyway, defensively: it costs nothing, it
   * cannot loosen who's accepted (anonymous/authenticated are never
   * privilege-bearing roles — stripping them only ever REMOVES candidates
   * for rejection, it can't manufacture a match that wasn't otherwise
   * there), and it means this function stays correct even if a future
   * caller ever passes getRoles(FALSE)/getRoles()'s shape by mistake — a
   * parent legitimately also holds the implicit 'authenticated' role and
   * must not be rejected for it either way. See
   * PortalLoginTest::testParentWithImplicitAuthenticatedRoleIsSafe() for
   * the case this specifically covers now.
   *
   * @param int $uid
   * @param string[] $roles
   *   Ordinarily the result of a real UserInterface::getRoles(TRUE) call
   *   (which — see above — never includes the locked anonymous/
   *   authenticated roles in the first place); tolerated here too if
   *   present, defensively.
   */
  public static function isSafeParentUser(int $uid, array $roles): bool {
    if ($uid === 1) {
      return FALSE;
    }
    $roles = array_values(array_diff($roles, ['anonymous', 'authenticated']));
    sort($roles);
    return $roles === ['parent'];
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
