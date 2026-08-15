<?php

/**
 * /civicrm/portal/logout : end the session, go back to the sign-in page.
 *
 * Design §7 lists "signing out ends the session" among the checks to make
 * before families are given the address. Until this existed there was nothing
 * to check: a parent on a shared computer — a library, a school machine, a
 * phone handed to somebody — could only close the browser and hope. The auth
 * cookie is a session cookie (PortalLogin::runLogin() passes $remember =
 * FALSE), so closing the browser does in fact end it, but "hope they close the
 * browser" is not a control and cannot be tested.
 *
 * The route is *always allow* and public like the other two portal routes. A
 * visitor who is already signed out must land on the sign-in page, not on a
 * permission error: the most likely person to hit this URL while signed out is
 * a parent who clicked Sign out twice.
 *
 * The redirect goes to civicrm/portal/request-link rather than to
 * wp-login.php. A parent has no password and no business seeing WordPress's
 * login form — the door they came in by is the only one they can use, so it is
 * the only one they are shown.
 *
 * ACCEPTED, and stated rather than left to be discovered: this is a GET, so a
 * third-party page can force a signed-in parent to sign out by pointing an
 * image or a link at it (logout CSRF). WordPress protects its own logout with
 * a nonce for this reason. The cost here is that a parent is signed out and
 * signs in again with a fresh link; no data is exposed and nothing is
 * destroyed. Carrying a nonce would mean the dashboard could no longer render
 * the link as static markup, which is a real complication of the front end for
 * an annoyance. Revisit if the portal ever grows an action that changes data.
 */
class CRM_Boosterportal_Page_PortalLogout extends CRM_Core_Page {

  public function run() {
    // wp_logout() clears the auth cookies, destroys the session tokens for
    // this login, and fires the 'wp_logout' action other plugins observe.
    // Guarded because the extension's headless PHPUnit bootstrap loads
    // CiviCRM's classloader alone, with no WordPress in the process at all.
    if (function_exists('wp_logout')) {
      wp_logout();
    }

    // CiviCRM keeps its own session state (the logged-in contact id, status
    // messages, the request-link CSRF token). Leaving it behind would mean a
    // parent who signed out still had a CiviCRM session pointing at their
    // contact for the rest of the browser session.
    CRM_Core_Session::singleton()->reset();

    CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/portal/request-link'));
  }

}
