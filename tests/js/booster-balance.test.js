'use strict';
// Quality-review IMPORTANT-3: proper, CI-wired coverage for js/booster-balance.js
// (previously only exercised by a throwaway Node harness during Task 17's
// live verification — see runbooks/dashboard.md). Zero dependencies beyond
// Node's own built-in test runner (node:test) and assert module — no
// jsdom, no bundler, nothing to install. See tests/js/dom-shim.js for how
// the widget's actual source file is loaded and stubbed.
const test = require('node:test');
const assert = require('node:assert/strict');
const { loadWidget } = require('./dom-shim');

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

/** A fetch stub that resolves like a successful, ok:true JSON response. */
function okFetch(payload) {
  return async () => ({ ok: true, status: 200, json: async () => payload });
}

/** A fetch stub that resolves like CiviCRM's own opaque 500 error body. */
function errorFetch() {
  return async () => ({
    ok: false,
    status: 500,
    json: async () => ({ error_code: '1', error_message: 'Sorry an error occurred and your request was not completed. (Error ID: xxxx)', status: 500 }),
  });
}

function makeElement(fetchImpl) {
  const BoosterBalance = loadWidget(fetchImpl);
  return new BoosterBalance();
}

test('shows a loading message synchronously, before the fetch resolves', () => {
  // Fetch that never settles within this test — only the SYNCHRONOUS part
  // of connectedCallback() (the loading message) should be visible yet.
  const el = makeElement(() => new Promise(() => {}));
  el.connectedCallback();
  const paragraphs = el.findAll('p');
  assert.equal(paragraphs.length, 1);
  assert.equal(paragraphs[0].textContent, 'Loading your balance…');
});

test('error state: a rejected fetch renders the fixed friendly message, no internals', async () => {
  const el = makeElement(() => Promise.reject(new Error('network down')));
  el.connectedCallback();
  await flush();
  const text = el.textContent;
  assert.match(text, /We could not load your balance right now/);
  assert.doesNotMatch(text, /network down/);
});

test('error state: a real captured CiviCRM 500 body also renders the fixed friendly message', async () => {
  const el = makeElement(errorFetch());
  el.connectedCallback();
  await flush();
  const text = el.textContent;
  assert.match(text, /We could not load your balance right now/);
  // The opaque-error contract: nothing from the response body — not the
  // CiviCRM error id, not "error_code", not "Sorry an error occurred" —
  // should ever reach the DOM.
  assert.doesNotMatch(text, /Error ID|error_code|Sorry an error occurred/);
});

test('MINOR-4: a response missing values[0] entirely is an ERROR, not the empty state', async () => {
  const el = makeElement(okFetch({}));
  el.connectedCallback();
  await flush();
  const text = el.textContent;
  assert.match(text, /We could not load your balance right now/);
  assert.doesNotMatch(text, /No billing account is linked/);
});

test('MINOR-5: a hung request aborts after the (overridable) timeout and renders the error state', async () => {
  const el = makeElement((url, opts) => new Promise((resolve, reject) => {
    opts.signal.addEventListener('abort', () => {
      const err = new Error('The operation was aborted.');
      err.name = 'AbortError';
      reject(err);
    });
  }));
  el.timeoutMs = 5; // production default is ~10000ms; kept tiny here so the test is fast.
  el.connectedCallback();
  await new Promise((resolve) => setTimeout(resolve, 30));
  assert.match(el.textContent, /We could not load your balance right now/);
});

test('empty state: explicit empty:true renders the "no billing account" message', async () => {
  const el = makeElement(okFetch({ values: [{ balance: null, flagged: false, partial: false, invoices: [], students: [], empty: true }] }));
  el.connectedCallback();
  await flush();
  assert.match(el.textContent, /No billing account is linked to your login yet/);
});

test('clean balance: renders the amount and no partial/flagged note', async () => {
  const el = makeElement(okFetch({
    values: [{ balance: 42.5, flagged: false, partial: false, invoices: [], students: [], empty: false }],
  }));
  el.connectedCallback();
  await flush();
  assert.match(el.textContent, /Balance due:/);
  assert.match(el.textContent, /\$42\.50/);
  assert.doesNotMatch(el.textContent, /covers only the student/);
});

test('partial state: renders the plain-language partial-view note', async () => {
  const el = makeElement(okFetch({
    values: [{ balance: 10, flagged: false, partial: true, invoices: [], students: [], empty: false }],
  }));
  el.connectedCallback();
  await flush();
  assert.match(el.textContent, /covers only the student\(s\) linked to your login/);
});

test('flagged state: renders IDENTICALLY to a clean balance (documented judgment call)', async () => {
  const cleanEl = makeElement(okFetch({
    values: [{ balance: 99, flagged: false, partial: false, invoices: [], students: [], empty: false }],
  }));
  cleanEl.connectedCallback();
  await flush();

  const flaggedEl = makeElement(okFetch({
    values: [{ balance: 99, flagged: true, partial: false, invoices: [], students: [], empty: false }],
  }));
  flaggedEl.connectedCallback();
  await flush();

  assert.equal(flaggedEl.textContent, cleanEl.textContent);
});

test('invoices present: renders a real Pay link per invoice with the expected text/attributes', async () => {
  const el = makeElement(okFetch({
    values: [{
      balance: 125.5,
      flagged: false,
      partial: false,
      empty: false,
      invoices: [{ InvoiceLink: 'https://qbo.example/pay/1', DocNumber: '1001', InvoiceId: 'abc', Balance: 125.5 }],
    }],
  }));
  el.connectedCallback();
  await flush();

  const links = el.findAll('a');
  assert.equal(links.length, 1);
  assert.equal(links[0].href, 'https://qbo.example/pay/1');
  assert.equal(links[0].target, '_blank');
  assert.equal(links[0].rel, 'noopener');
  assert.match(links[0].textContent, /Pay invoice 1001 \(\$125\.50\) in QuickBooks/);
});

test('IMPORTANT-1 (XSS): a hostile DocNumber renders as inert text, never as markup', async () => {
  const hostileDocNumber = '"><script>window.__pwned = true;</script>';
  const el = makeElement(okFetch({
    values: [{
      balance: 10,
      flagged: false,
      partial: false,
      empty: false,
      invoices: [{ InvoiceLink: 'https://qbo.example/pay/2', DocNumber: hostileDocNumber, InvoiceId: 'xyz', Balance: 10 }],
    }],
  }));
  el.connectedCallback();
  await flush();

  // 1) No <script> element anywhere in the rendered subtree — the payload
  //    never became markup/an element, under any tag name.
  assert.equal(el.findAll('script').length, 0);
  for (const node of el.allDescendants()) {
    assert.notEqual(node.tagName, 'SCRIPT');
  }

  // 2) The hostile string DOES appear, but only as literal, inert TEXT
  //    inside the Pay link — proving it was assigned via textContent, not
  //    parsed as HTML (a real browser would behave identically: textContent
  //    never executes or restructures its input).
  const links = el.findAll('a');
  assert.equal(links.length, 1);
  assert.ok(links[0].textContent.includes(hostileDocNumber),
    'the hostile string should appear verbatim as inert text');

  // 3) Nothing in this shim has an HTML parser at all (see dom-shim.js's
  //    header comment) — belt-and-braces confirmation that innerHTML, if it
  //    were ever used, was not what produced this output.
  assert.equal(el.innerHTML, undefined, 'no innerHTML assignment should have happened anywhere in the render path');
});

test('IMPORTANT-1: a non-https InvoiceLink is rejected — no Pay link, balance still shown', async () => {
  const el = makeElement(okFetch({
    values: [{
      balance: 55,
      flagged: false,
      partial: false,
      empty: false,
      invoices: [
        { InvoiceLink: 'javascript:alert(1)', DocNumber: '2001', Balance: 55 },
        { InvoiceLink: 'http://not-https.example/pay', DocNumber: '2002', Balance: 55 },
        { InvoiceLink: 'not a url at all', DocNumber: '2003', Balance: 55 },
      ],
    }],
  }));
  el.connectedCallback();
  await flush();

  assert.equal(el.findAll('a').length, 0, 'no invoice with a non-https link should ever produce a Pay link');
  assert.match(el.textContent, /Balance due:/);
  assert.match(el.textContent, /\$55\.00/);
  assert.match(el.textContent, /Online payment links are unavailable right now/);
});

test('safeHttpsUrl: accepts only well-formed https:// URLs', () => {
  const BoosterBalance = loadWidget(okFetch({}));
  const el = new BoosterBalance();
  assert.equal(el.safeHttpsUrl('https://qbo.example/x'), 'https://qbo.example/x');
  assert.equal(el.safeHttpsUrl('http://qbo.example/x'), null);
  assert.equal(el.safeHttpsUrl('javascript:alert(1)'), null);
  assert.equal(el.safeHttpsUrl('  https://qbo.example/x'), null); // must actually START WITH https://
  assert.equal(el.safeHttpsUrl(''), null);
  assert.equal(el.safeHttpsUrl(null), null);
  assert.equal(el.safeHttpsUrl(undefined), null);
  assert.equal(el.safeHttpsUrl(12345), null);
});

test('CSS hooks: the balance card, figure and Pay link carry the classes the theme styles', async () => {
  const el = makeElement(okFetch({
    values: [{
      balance: 125.5,
      flagged: false,
      partial: false,
      empty: false,
      invoices: [{ InvoiceLink: 'https://qbo.example/pay/1', DocNumber: '1001', InvoiceId: 'abc', Balance: 125.5 }],
    }],
  }));
  el.connectedCallback();
  await flush();

  assert.equal(el.findAllByClass('booster-balance').length, 1);
  assert.equal(el.findAllByClass('booster-balance-amount').length, 1);

  const figure = el.findAllByClass('booster-balance-figure');
  assert.equal(figure.length, 1, 'the currency figure needs its own hook — the theme sets it large and green');
  assert.equal(figure[0].textContent, '$125.50');

  const pay = el.findAllByClass('booster-balance-pay');
  assert.equal(pay.length, 1);
  assert.equal(pay[0].tagName, 'A');
  assert.ok(pay[0].className.split(/\s+/).includes('button'),
    'the Pay link must KEEP the plain .button class it already had');
});

test('CSS hooks: loading, empty, error and no-links states each carry a class', async () => {
  const loadingEl = makeElement(() => new Promise(() => {}));
  loadingEl.connectedCallback();
  assert.equal(loadingEl.findAllByClass('booster-balance-loading').length, 1);

  const emptyEl = makeElement(okFetch({
    values: [{ balance: null, flagged: false, partial: false, invoices: [], students: [], empty: true }],
  }));
  emptyEl.connectedCallback();
  await flush();
  assert.equal(emptyEl.findAllByClass('booster-balance-empty').length, 1);

  const errorEl = makeElement(() => Promise.reject(new Error('network down')));
  errorEl.connectedCallback();
  await flush();
  assert.equal(errorEl.findAllByClass('booster-balance-error').length, 1);

  const noLinksEl = makeElement(okFetch({
    values: [{
      balance: 55,
      flagged: false,
      partial: false,
      empty: false,
      invoices: [{ InvoiceLink: 'http://not-https.example/pay', DocNumber: '2002', Balance: 55 }],
    }],
  }));
  noLinksEl.connectedCallback();
  await flush();
  assert.equal(noLinksEl.findAllByClass('booster-balance-nolinks').length, 1);
});
