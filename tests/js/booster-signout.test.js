'use strict';
// Coverage for js/booster-signout.js — the dashboard's Sign out control.
// Same harness as booster-balance.test.js: Node's built-in test runner and
// the zero-dependency shim in dom-shim.js, no jsdom, nothing to install.
const test = require('node:test');
const assert = require('node:assert/strict');
const { loadSignout } = require('./dom-shim');

test('renders a single Sign out link', () => {
  const BoosterSignout = loadSignout({ url: (path) => '/index.php?q=' + path });
  const el = new BoosterSignout();
  el.connectedCallback();

  const links = el.findAll('a');
  assert.equal(links.length, 1, 'exactly one anchor');
  assert.equal(links[0].textContent, 'Sign out');
});

test('carries the class the theme styles', () => {
  // Renaming this class silently unstyles the control in the site repository's
  // CSS, which is why it is asserted rather than left to a code reader.
  const BoosterSignout = loadSignout({ url: (path) => '/index.php?q=' + path });
  const el = new BoosterSignout();
  el.connectedCallback();

  assert.equal(el.findAllByClass('booster-signout-link').length, 1);
});

test('builds the href through CRM.url, so it is right under both URL modes', () => {
  // The whole reason this is a custom element and not a plain anchor in the
  // Afform markup. A site serving the ?page=CiviCRM&q=... form must get that
  // form, not a clean path that 404s.
  const BoosterSignout = loadSignout({ url: (path) => '/?page=CiviCRM&q=' + path });
  const el = new BoosterSignout();
  el.connectedCallback();

  assert.equal(el.findAll('a')[0].href, '/?page=CiviCRM&q=civicrm/portal/logout');
});

test('points at the logout route, not at WordPress own login form', () => {
  // A parent has no password. wp-login.php?action=logout would work, but it
  // lands them somewhere they can do nothing with, and it needs a nonce.
  const seen = [];
  const BoosterSignout = loadSignout({ url: (path) => { seen.push(path); return '/x'; } });
  const el = new BoosterSignout();
  el.connectedCallback();

  assert.deepEqual(seen, ['civicrm/portal/logout']);
});

test('falls back to the clean path when CiviCRM JS is absent', () => {
  // Not a state that should occur on a real dashboard, but the link must
  // still go somewhere sensible rather than render as undefined.
  const BoosterSignout = loadSignout(undefined);
  const el = new BoosterSignout();
  el.connectedCallback();

  assert.equal(el.findAll('a')[0].href, '/civicrm/portal/logout');
});
