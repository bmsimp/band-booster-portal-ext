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
 */
class CRM_Boosterportal_Page_PortalLogin extends CRM_Core_Page {

  private const SESSION_CSRF_KEY = 'boosterportal_request_link_csrf';

  public function run() {
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
    $ufMatch = \Civi\Api4\UFMatch::get(FALSE)
      ->addWhere('contact_id', '=', $cid)->execute()->first();
    if (!$ufMatch) {
      CRM_Core_Session::setStatus(ts('No portal account exists for this address yet. Contact the boosters.'), ts('Sign in'), 'error');
      CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/portal/request-link'));
      return;
    }
    $user = \Drupal\user\Entity\User::load($ufMatch['uf_id']);
    if (!$user) {
      // Defensive: UFMatch row survives a Drupal user deletion.
      CRM_Core_Session::setStatus(ts('No portal account exists for this address yet. Contact the boosters.'), ts('Sign in'), 'error');
      CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/portal/request-link'));
      return;
    }
    user_login_finalize($user);
    CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/portal'));
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
