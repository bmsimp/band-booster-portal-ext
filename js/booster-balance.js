// Task 17 (§4.9): the ONE custom widget in the system. Fetches the logged-in
// parent's family balance (Civi\Api4\Action\BoosterPortal\GetMyBalance) and
// renders it plus Pay links. Framework-free on purpose — "what must I learn
// to maintain this?" must stay answerable with: JavaScript.
//
// No fixed pixel widths anywhere below (§4.9 mobile sanity — see
// runbooks/dashboard.md's "still needs a human with a phone" list): every
// element here is a plain block/paragraph/anchor that wraps and stacks
// naturally at any viewport width, and the "Pay" affordance is a real
// <a href> (works without JS once rendered, opens the host's native "open
// link" behaviour on a phone, no click-handler-only button).
//
// SECURITY (quality-review IMPORTANT-1): InvoiceLink, DocNumber, and
// InvoiceId all come from QuickBooks, which anyone with QBO write access
// (or a compromised QBO account) can put arbitrary text into — DocNumber in
// particular is a freeform, user-editable field on the QBO side. This file
// therefore NEVER builds markup by string-interpolating those values into
// innerHTML (the earlier version did exactly that, which is a stored-XSS
// hole on a bearer-authenticated parent session — a `"` or `<` in DocNumber
// would have broken out of the template). Every element below is built with
// document.createElement()/textContent, which — unlike innerHTML — never
// parses its input as markup, so an attacker-controlled string can only
// ever render as inert visible text, never as an executing script or an
// injected element. InvoiceLink is additionally validated as an actual
// https:// URL before it's ever used as an href — see safeHttpsUrl() below —
// and simply dropped (balance still shown, no Pay link for that invoice) if
// it fails.
// THEMING (public-face design §4.3): the class names below —
// booster-balance, -amount, -figure, -pay, -note, -loading, -empty, -error,
// -nolinks — are the ONLY hooks the site theme has on this widget
// (band-booster-portal: site/web/themes/custom/fznband/css/portal.css).
// Renaming one silently unstyles part of the parent dashboard in the other
// repository, so tests/js/booster-balance.test.js asserts on them. Adding a
// class here is cheap; renaming one is a two-repository change.
class BoosterBalance extends HTMLElement {
  // Overridable per-instance/class for testing (tests/js/) — production
  // default is the real ~10s budget (quality-review MINOR-5): a hung
  // request (QBO/network trouble) must not leave a parent staring at
  // "Loading your balance…" forever. Aborting routes into the exact same
  // opaque error path as any other fetch failure below.
  static TIMEOUT_MS = 10000;

  connectedCallback() {
    this.clear();
    const loading = document.createElement('p');
    loading.className = 'booster-balance-loading';
    loading.textContent = 'Loading your balance…';
    this.appendChild(loading);

    const controller = new AbortController();
    const timeoutMs = this.timeoutMs || this.constructor.TIMEOUT_MS;
    const timeoutId = setTimeout(() => controller.abort(), timeoutMs);

    fetch('/civicrm/ajax/api4/BoosterPortal/getMyBalance', {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'params=' + encodeURIComponent(JSON.stringify({})),
      credentials: 'same-origin',
      signal: controller.signal,
    })
      .then((r) => { if (!r.ok) throw new Error(r.status); return r.json(); })
      .then((data) => {
        // quality-review MINOR-4: a response missing values[0] entirely
        // (malformed/unexpected shape) is a FAILURE, not "nothing to show" —
        // routing it to the empty-account message would tell a parent they
        // owe nothing when really the response just didn't parse as
        // expected. Only an explicit `empty: true` from the server (handled
        // inside render()) means "no billing account linked."
        const v = data && data.values && data.values[0];
        if (!v) {
          throw new Error('getMyBalance: malformed response (missing values[0])');
        }
        this.render(v);
      })
      .catch(() => {
        // I3/opaque-error contract (GetMyBalance::failOpaque()): whatever
        // failed — network, timeout, QBO down, a derivation error, a
        // malformed response — the response body is never inspected for
        // message text here. Friendly, fixed, no internals, ever.
        this.renderError();
      })
      .finally(() => clearTimeout(timeoutId));
  }

  /** Removes all current children without ever parsing a string as markup. */
  clear() {
    while (this.firstChild) {
      this.removeChild(this.firstChild);
    }
  }

  renderError() {
    this.clear();
    const p = document.createElement('p');
    p.className = 'booster-balance-error';
    p.textContent = 'We could not load your balance right now. Please try again later — your account is not affected.';
    this.appendChild(p);
  }

  /**
   * Only accepts an https:// URL, validated two ways (quality-review
   * IMPORTANT-1): a plain string check (startsWith) so a non-string or an
   * obviously-wrong scheme is rejected before any parsing happens, AND a
   * real new URL() parse so a value that merely starts with the right
   * substring but isn't actually a well-formed URL (e.g. embedded
   * whitespace/control characters some browsers tolerate) is also rejected.
   * Returns the parsed, re-serialized href on success, or null — callers
   * must treat null as "no link for this invoice," never as "found error."
   */
  safeHttpsUrl(raw) {
    if (typeof raw !== 'string' || !raw.startsWith('https://')) {
      return null;
    }
    try {
      const parsed = new URL(raw);
      return parsed.protocol === 'https:' ? parsed.href : null;
    }
    catch (e) {
      return null;
    }
  }

  render(v) {
    this.clear();
    if (v.empty) {
      const p = document.createElement('p');
      p.className = 'booster-balance-empty';
      p.textContent = 'No billing account is linked to your login yet. If you expected one, contact the boosters.';
      this.appendChild(p);
      return;
    }

    const fmt = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' });
    const container = document.createElement('div');
    container.className = 'booster-balance';

    const balanceP = document.createElement('p');
    balanceP.className = 'booster-balance-amount';
    balanceP.appendChild(document.createTextNode('Balance due: '));
    const strong = document.createElement('strong');
    strong.className = 'booster-balance-figure';
    strong.textContent = fmt.format(v.balance);
    balanceP.appendChild(strong);
    container.appendChild(balanceP);

    // R2/'partial' (BalanceService docblock): a BY-DESIGN incomplete view —
    // this parent's verified students are only SOME of a billing
    // household's students (a split family, a revoked/expired edge, ...),
    // so the figure shown is the sum of just the students this parent can
    // see, not a cross-checked household total. Not an error, not
    // actionable by the parent alone — shown as a plain informational note.
    if (v.partial) {
      const note = document.createElement('p');
      note.className = 'booster-balance-note';
      note.textContent = 'This figure covers only the student(s) linked to your login — if your '
        + 'family has students not shown here, or this does not look right, please contact the boosters.';
      container.appendChild(note);
    }

    // 'flagged' (R2, BalanceService docblock): a GENUINE cross-check
    // mismatch on the QBO side (BalanceWithJobs disagreed with the expected
    // sum, or was missing outright) — the treasurer's signal, already
    // logged server-side at WARNING by GetMyBalance. Per §4.6/BalanceService,
    // the figure shown when flagged is already deliberately the HIGHER of
    // the two candidate numbers (never an understatement) — showing a
    // parent "there might be a discrepancy" exposes a
    // reconciliation-internal concept with no useful action for them to
    // take, and a false-alarm "something's wrong with YOUR account" reading
    // is worse than saying nothing. JUDGMENT CALL (documented per the task,
    // revisit if parents start reporting confusion): render NOTHING
    // distinct for 'flagged' below — the balance and Pay links display
    // exactly as they would for a clean household.

    // Build each Pay link with real DOM nodes, never string-built markup —
    // DocNumber/InvoiceId/InvoiceLink are all QBO-sourced and untrusted.
    // Anything that fails the https:// URL check is simply skipped (no Pay
    // link for that invoice; the balance total above is unaffected).
    const invoices = (v.invoices || [])
      .map((i) => ({ ...i, safeLink: this.safeHttpsUrl(i.InvoiceLink) }))
      .filter((i) => i.safeLink);

    invoices.forEach((i) => {
      const p = document.createElement('p');
      const a = document.createElement('a');
      a.className = 'button booster-balance-pay';
      a.href = i.safeLink;
      a.target = '_blank';
      a.rel = 'noopener';
      // textContent, not innerHTML: DocNumber (freeform/QBO-write-editable)
      // and InvoiceId render as plain, inert text no matter what characters
      // they contain — a "><script>...</script> DocNumber shows up on the
      // page exactly as that literal string, never as markup.
      a.textContent = `Pay invoice ${i.DocNumber || i.InvoiceId} (${fmt.format(i.Balance)}) in QuickBooks →`;
      p.appendChild(a);
      container.appendChild(p);
    });

    if (v.balance > 0 && !invoices.length) {
      const p = document.createElement('p');
      p.className = 'booster-balance-nolinks';
      p.textContent = 'Online payment links are unavailable right now — your invoice can be paid from the emailed QuickBooks invoice.';
      container.appendChild(p);
    }

    this.appendChild(container);
  }
}
customElements.define('booster-balance', BoosterBalance);
