// Task 17 (§4.9): the ONE custom widget in the system. Fetches the logged-in
// parent's family balance (Civi\Api4\Action\BoosterPortal\GetMyBalance) and
// renders it plus Pay links. Framework-free on purpose — "what must I learn
// to maintain this?" must stay answerable with: JavaScript.
//
// No fixed pixel widths anywhere below (§4.9 mobile sanity — see the Task 17
// report for the phone-check list a human should still run): every element
// here is a plain block/paragraph/anchor that wraps and stacks naturally at
// any viewport width, and the "Pay" affordance is a real <a href> (works
// without JS once rendered, opens the host's native "open link" behaviour on
// a phone, no click-handler-only button).
//
// Payload shape (Civi\Api4\Action\BoosterPortal\GetMyBalance::_run(),
// Civi\Boosterportal\BalanceService — read both docblocks for the full
// reasoning):
//   { balance: float|null, flagged: bool, partial: bool,
//     students: string[], invoices: array, empty: bool }
//
// 'partial' (R2, BalanceService docblock): a BY-DESIGN incomplete view — this
// parent's verified students are only SOME of a billing household's students
// (a split family, a revoked/expired edge, ...), so the figure shown is the
// sum of just the students this parent can see, not a cross-checked household
// total. Not an error, not actionable by the parent alone — shown as a plain
// informational note.
//
// 'flagged' (R2, same docblock): a GENUINE cross-check mismatch on the QBO
// side (BalanceWithJobs disagreed with the expected sum, or was missing
// outright) — the treasurer's signal, already logged server-side at WARNING
// by GetMyBalance. Per §4.6/BalanceService, the figure shown when flagged is
// already deliberately the HIGHER of the two candidate numbers (never an
// understatement) — showing a parent "there might be a discrepancy" exposes
// a reconciliation-internal concept (what is BalanceWithJobs, what does a
// mismatch mean) with no useful action for them to take, and a false-alarm
// "something's wrong with YOUR account" reading is worse than saying
// nothing, since the number itself is already the safe (never-too-low)
// figure. JUDGMENT CALL (documented per the task, revisit if parents start
// reporting confusion): render NOTHING distinct for 'flagged' — the balance
// and Pay links display exactly as they would for a clean household. The
// treasurer already has the WARNING log; that is where this belongs.
class BoosterBalance extends HTMLElement {
  connectedCallback() {
    this.innerHTML = '<p>Loading your balance…</p>';
    fetch('/civicrm/ajax/api4/BoosterPortal/getMyBalance', {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'params=' + encodeURIComponent(JSON.stringify({})),
      credentials: 'same-origin',
    })
      .then((r) => { if (!r.ok) throw new Error(r.status); return r.json(); })
      .then((data) => this.render(data.values[0]))
      .catch(() => {
        // I3/opaque-error contract (GetMyBalance::failOpaque()): whatever
        // failed — network, QBO down, a derivation error — the response
        // body is never inspected for message text here. Friendly, fixed,
        // no internals, ever.
        this.innerHTML = '<p>We could not load your balance right now. '
          + 'Please try again later — your account is not affected.</p>';
      });
  }

  render(v) {
    if (!v || v.empty) {
      this.innerHTML = '<p>No billing account is linked to your login yet. '
        + 'If you expected one, contact the boosters.</p>';
      return;
    }
    const fmt = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' });
    const invoices = (v.invoices || []).filter((i) => i.InvoiceLink);
    const partialNote = v.partial
      ? '<p class="booster-balance-note">This figure covers only the student(s) linked to your '
        + 'login — if your family has students not shown here, or this does not look right, '
        + 'please contact the boosters.</p>'
      : '';
    this.innerHTML = `
      <div class="booster-balance">
        <p>Balance due: <strong>${fmt.format(v.balance)}</strong></p>
        ${partialNote}
        ${invoices.map((i) => `<p><a class="button" href="${i.InvoiceLink}" target="_blank" rel="noopener">
            Pay invoice ${i.DocNumber || i.InvoiceId} (${fmt.format(i.Balance)}) in QuickBooks &rarr;</a></p>`).join('')}
        ${v.balance > 0 && !invoices.length
          ? '<p>Online payment links are unavailable right now — your invoice can be paid from the emailed QuickBooks invoice.</p>' : ''}
      </div>`;
  }
}
customElements.define('booster-balance', BoosterBalance);
