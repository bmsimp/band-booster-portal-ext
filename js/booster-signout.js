'use strict';
// The dashboard's Sign out control.
//
// Design §7 lists "signing out ends the session" among the checks to make
// before families are given the address. The route that does the work is
// CRM/Boosterportal/Page/PortalLogout.php; this is the only thing a parent
// ever sees of it.
//
// A custom element rather than a plain anchor in the Afform markup, for one
// reason: the href has to be right under BOTH URL modes. CiviCRM serves clean
// paths (/civicrm/portal/logout) on a site whose base page is in place, and
// the ?page=CiviCRM&q=... form otherwise, and a dev site can differ from
// production on exactly this. CRM.url() is CiviCRM's own answer to that
// question and is already loaded on every CiviCRM page, so the link is built
// through it rather than hard-coded and hoped for.
//
// Framework-free, like js/booster-balance.js beside it, because that is the
// pattern this project has: no build step, no dependency to update, nothing
// for a volunteer to install before they can change a word of it.
//
// THEMING: booster-signout-link is the only hook the site theme has on this,
// and tests/js/booster-signout.test.js asserts on it. Renaming it silently
// unstyles the control.
class BoosterSignout extends HTMLElement {

  connectedCallback() {
    const link = document.createElement('a');
    link.className = 'booster-signout-link';
    link.textContent = 'Sign out';
    // The fallback is the clean path rather than the query-string form
    // because it is the one production serves. It only applies if CiviCRM's
    // own JS has not loaded, in which case the dashboard around this link is
    // already broken in more visible ways.
    link.href = (typeof CRM !== 'undefined' && CRM && typeof CRM.url === 'function')
      ? CRM.url('civicrm/portal/logout')
      : '/civicrm/portal/logout';
    this.appendChild(link);
  }

}

customElements.define('booster-signout', BoosterSignout);
