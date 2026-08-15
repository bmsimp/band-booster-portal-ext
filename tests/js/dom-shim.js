'use strict';
// Minimal, zero-dependency DOM shim for testing js/booster-balance.js under
// Node's built-in test runner (quality-review IMPORTANT-3: "prefer zero-dep
// node:test"). This is deliberately NOT a general-purpose DOM/HTML parser —
// it implements only the handful of Element/Node members the widget
// actually uses (createElement, appendChild, removeChild, textContent,
// className, and plain property assignment for href/target/rel).
//
// That narrowness is itself part of the XSS proof, not just a shortcut:
// this shim has no HTML PARSER at all. There is no code path anywhere in it
// that turns a string into markup/child elements — textContent only ever
// stores/returns a literal string, and there is no innerHTML setter that
// does anything but record the raw string for inspection (see FakeNode
// below). So if the widget ever regressed to building markup by string
// concatenation, a test asserting "no <script> element exists in the
// subtree" would still hold, because THIS SHIM structurally cannot create
// an element from a string no matter what the widget hands it — the only
// way an element appears in the tree at all is via a real
// document.createElement() + appendChild() call, exactly like a real
// browser's textContent/createElement APIs. A regression that reintroduced
// innerHTML would be caught separately by the dedicated assertions in
// booster-balance.test.js that check the raw string never got treated as
// markup.
class FakeNode {
  constructor(tagName) {
    this.tagName = tagName ? String(tagName).toUpperCase() : undefined;
    this.childNodes = [];
    this.className = '';
    this._text = '';
    // NOT inferred from a missing tagName — the custom element itself
    // (BoosterBalance, via `new BoosterBalance()`, which inherits this
    // constructor with no arguments) also has no tagName, and is very much
    // NOT a text node. Only document.createTextNode() below sets this.
    this.isTextNode = false;
  }

  appendChild(child) {
    this.childNodes.push(child);
    return child;
  }

  removeChild(child) {
    const i = this.childNodes.indexOf(child);
    if (i >= 0) {
      this.childNodes.splice(i, 1);
    }
    return child;
  }

  get firstChild() {
    return this.childNodes[0] || null;
  }

  set textContent(value) {
    // Real DOM semantics: setting textContent removes all existing
    // children and replaces them with a single, literal text node — never
    // parsed as markup. This shim just stores the string directly, which
    // is the same guarantee with less machinery.
    this.childNodes = [];
    this._text = value == null ? '' : String(value);
  }

  get textContent() {
    if (this.isTextNode) {
      return this._text;
    }
    if (this.childNodes.length === 0) {
      return this._text;
    }
    return this.childNodes.map((c) => c.textContent).join('');
  }

  // Deliberately inert: records what was assigned (so a test CAN detect an
  // accidental innerHTML usage) but never parses it into child
  // elements — there is no markup path in this shim at all.
  set innerHTML(value) {
    this._innerHTMLAssigned = value;
    this.childNodes = [];
    this._text = '';
  }

  get innerHTML() {
    return this._innerHTMLAssigned;
  }

  /** Test helper: every node in this subtree (self included), depth-first. */
  allDescendants() {
    const out = [this];
    for (const child of this.childNodes) {
      if (child.allDescendants) {
        out.push(...child.allDescendants());
      }
      else {
        out.push(child);
      }
    }
    return out;
  }

  /** Test helper: all element (non-text) descendants with a given tag. */
  findAll(tagName) {
    const upper = tagName.toUpperCase();
    return this.allDescendants().filter((n) => n.tagName === upper);
  }

  /**
   * Test helper: all descendants carrying a given class name. The theme
   * (styled in the stock theme's Additional CSS, per design §4.5 — there is
   * no custom theme or stylesheet in either repository)
   * styles this widget entirely through these class names, so they are a
   * contract, not decoration — hence assertions about them.
   */
  findAllByClass(className) {
    return this.allDescendants().filter((n) => typeof n.className === 'string'
      && n.className.split(/\s+/).includes(className));
  }
}

function createElement(tagName) {
  return new FakeNode(tagName);
}

function createTextNode(text) {
  const n = new FakeNode(undefined);
  n.isTextNode = true;
  n._text = String(text);
  return n;
}

// One-time compile of the widget source into a factory function that takes
// the free identifiers the widget file references as GLOBALS (document,
// HTMLElement, customElements, fetch, AbortController, setTimeout,
// clearTimeout) as ordinary named parameters instead. This is the same
// trick browsers themselves rely on (a <script> tag's top-level `var`/
// `function`/`class` bindings are just properties of/closures over the
// global object) but done explicitly and per-call, so each test can pass
// its OWN fetch implementation and get back a freshly defined BoosterBalance
// class closed over exactly that fetch — no shared mutable state between
// tests, and no need for `with` (disallowed in strict mode, and easy to get
// subtly wrong). Anything the widget references that ISN'T one of these
// parameters (Error, Array, Intl, URL, JSON, Math, ...) falls through to
// Node's real, spec-compliant globals untouched, which is exactly what's
// wanted — only the browser-environment pieces need stubbing.
const compile = new Function(
  'document', 'HTMLElement', 'customElements', 'fetch', 'AbortController', 'setTimeout', 'clearTimeout',
  `${require('fs').readFileSync(require('path').join(__dirname, '..', '..', 'js', 'booster-balance.js'), 'utf8')}
   return BoosterBalance;`
);

/**
 * Returns a fresh BoosterBalance class, wired to the given fetch
 * implementation and to this shim's FakeNode-based document/HTMLElement.
 * Each call is fully independent (no shared state across tests).
 *
 * @param {Function} fetchImpl (url, options) => Promise<Response-like>
 */
function loadWidget(fetchImpl) {
  return compile(
    { createElement, createTextNode },
    FakeNode,
    { define: () => {} },
    fetchImpl,
    globalThis.AbortController,
    globalThis.setTimeout,
    globalThis.clearTimeout,
  );
}

// The same trick for js/booster-signout.js. A second compile rather than a
// parameterised one: the two widgets reference different free identifiers
// (the balance widget needs fetch/AbortController/timers, the sign-out
// control needs CRM), and passing every identifier either might ever want to
// both of them would make each test's stubs less obvious, not more.
const compileSignout = new Function(
  'document', 'HTMLElement', 'customElements', 'CRM',
  `${require('fs').readFileSync(require('path').join(__dirname, '..', '..', 'js', 'booster-signout.js'), 'utf8')}
   return BoosterSignout;`
);

/**
 * Returns a fresh BoosterSignout class wired to this shim's document and to
 * the given CRM stub. Pass undefined for CRM to exercise the "CiviCRM's JS
 * never loaded" fallback.
 *
 * @param {object|undefined} crm An object with a url(path) method, or undefined.
 */
function loadSignout(crm) {
  return compileSignout(
    { createElement, createTextNode },
    FakeNode,
    { define: () => {} },
    crm,
  );
}

module.exports = { FakeNode, createElement, createTextNode, loadWidget, loadSignout };
