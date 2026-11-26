// Checkout page script: checkout and registration keep separate verification state.
//
//   node tests/test-verify-js.mjs
//
// Runs assets/js/didit-verify.js in node:vm against a tiny fake DOM, SDK and fetch.

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const source = readFileSync(fileURLToPath(new URL('../assets/js/didit-verify.js', import.meta.url)), 'utf8');

let passed = 0;
let failed = 0;
function check(ok, name) {
  if (ok) { passed++; console.log('PASS ' + name); } else { failed++; console.log('FAIL ' + name); }
}

class El {
  constructor(tag, attrs = {}) {
    this.tagName = tag.toUpperCase();
    this.attrs = {};
    this.dataset = {};
    this.children = [];
    this.parentNode = null;
    this.textContent = '';
    this.value = attrs.value || '';
    this.disabled = false;
    this.id = attrs.id || '';
    this.name = attrs.name || '';
    this.className = attrs.class || '';
    const self = this;
    this.classList = {
      list: () => self.className.split(/\s+/).filter(Boolean),
      contains: (c) => self.classList.list().includes(c),
      add: (...c) => { self.className = [...new Set([...self.classList.list(), ...c])].join(' '); },
      remove: (...c) => { self.className = self.classList.list().filter((x) => !c.includes(x)).join(' '); },
      toggle: (c, on) => (on ? self.classList.add(c) : self.classList.remove(c)),
    };
    for (const [k, v] of Object.entries(attrs.data || {})) this.dataset[k] = v;
  }
  append(child) { child.parentNode = this; this.children.push(child); return child; }
  setAttribute(k, v) { this.attrs[k] = v; }
  insertAdjacentElement(_where, node) { this.parentNode.append(node); }
  *walk() { for (const c of this.children) { yield c; yield* c.walk(); } }
  matches(sel) {
    if (sel.startsWith('.')) return this.classList.contains(sel.slice(1));
    const m = sel.match(/^input\[name="(.+)"\]$/);
    if (m) return this.tagName === 'INPUT' && this.name === m[1];
    return false;
  }
  querySelector(sel) { for (const e of this.walk()) if (e.matches(sel)) return e; return null; }
  querySelectorAll(sel) { return [...this.walk()].filter((e) => e.matches(sel)); }
  closest(sel) { let e = this; while (e) { if (e.matches && e.matches(sel)) return e; e = e.parentNode; } return null; }
}

function page({ url = 'https://shop.test/checkout/', storage = {}, display = '', queued = [] } = {}) {
  const body = new El('body');
  const checkoutWrap = body.append(new El('div', { class: 'didit-verify-wrap' }));
  checkoutWrap.append(new El('input', { id: 'didit_session_id', name: 'didit_session_id' }));
  const checkoutBtn = checkoutWrap.append(new El('button', { class: 'didit-verify-btn', data: { wc: '1', placement: 'checkout', text: 'Verify', success: 'Verified', ...(display ? { display } : {}) } }));
  const reg = body.append(new El('div', { class: 'didit-registration' }));
  const regWrap = reg.append(new El('div', { class: 'didit-verify-wrap' }));
  const regBtn = regWrap.append(new El('button', { class: 'didit-verify-btn', data: { placement: 'registration', text: 'Verify account', success: 'Verified', ...(display ? { display } : {}) } }));
  reg.append(new El('input', { name: 'didit_registration_session_id' }));

  const listeners = {};
  const document = {
    readyState: 'complete',
    addEventListener: (t, f) => { (listeners[t] = listeners[t] || []).push(f); },
    dispatchEvent: () => true,
    getElementById: (id) => [...body.walk()].find((e) => e.id === id) || null,
    querySelector: (s) => body.querySelector(s),
    querySelectorAll: (s) => body.querySelectorAll(s),
    createElement: (t) => new El(t),
  };
  const store = { ...storage };
  const sdk = { started: [] };
  const replies = [...queued];
  const requests = [];
  const window = {
    location: { href: url, assign(u) { window.assigned = u; }, reload() {} },
    history: { replaceState: (_s, _t, u) => { window.location.href = u; } },
    sessionStorage: {
      getItem: (k) => (k in store ? store[k] : null),
      setItem: (k, v) => { store[k] = String(v); },
      removeItem: (k) => { delete store[k]; },
    },
    setTimeout: () => 0,
    diditConfig: { restUrl: 'https://shop.test/wp-json/didit/v1/session', nonce: 'n', mode: 'api', i18n: {} },
    DiditSDK: { DiditSdk: { shared: { startVerification: (o) => sdk.started.push(o) } } },
  };
  const fetch = (u, opts) => {
    requests.push({ url: u, body: JSON.parse(opts.body) });
    const data = replies.shift();
    return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(data) });
  };
  const context = { window, document, fetch, URL, JSON, CustomEvent: class { constructor(t, o) { this.type = t; this.detail = o && o.detail; } }, console };
  vm.createContext(context);
  vm.runInContext(source, context);
  const click = (btn) => { for (const f of listeners.click || []) f({ target: btn, preventDefault() {} }); };
  return {
    window, document, store, sdk, replies, requests, click, checkoutBtn, regBtn,
    checkoutField: () => document.getElementById('didit_session_id').value,
    regField: () => reg.querySelector('input[name="didit_registration_session_id"]').value,
    complete: (result) => window.DiditSDK.DiditSdk.shared.onComplete(result),
  };
}

const flush = () => new Promise((r) => setTimeout(r, 0));
const S1 = '11111111-1111-4111-8111-111111111111';
const S2 = '22222222-2222-4222-8222-222222222222';

async function verify(p, btn, sessionId) {
  p.replies.push({ url: 'https://verify.test/' + sessionId, sessionId });
  p.click(btn);
  await flush(); await flush();
  p.replies.push({ status: 'Approved', sessionId });
  p.complete({ type: 'completed', session: { sessionId } });
  await flush(); await flush();
}

// Checkout first, then registration.
{
  const p = page();
  await verify(p, p.checkoutBtn, S1);
  await verify(p, p.regBtn, S2);
  check(p.checkoutField() === S1, 'checkout then registration: the checkout proof survives the registration approval');
  check(p.regField() === S2, 'checkout then registration: the registration field holds its own session');
  check(p.requests.filter((r) => r.url.endsWith('/session')).map((r) => r.body.placement).join() === 'checkout,registration', 'each button asks for its own placement');
}

// Registration first, then checkout.
{
  const p = page();
  await verify(p, p.regBtn, S2);
  await verify(p, p.checkoutBtn, S1);
  check(p.regField() === S2, 'registration then checkout: the registration proof survives the checkout approval');
  check(p.checkoutField() === S1, 'registration then checkout: the checkout field holds its own session');
}

// Cancelling one placement leaves the other's proof alone.
{
  const p = page();
  await verify(p, p.checkoutBtn, S1);
  p.replies.push({ url: 'https://verify.test/x', sessionId: S2 });
  p.click(p.regBtn);
  await flush(); await flush();
  p.complete({ type: 'cancelled' });
  check(p.checkoutField() === S1, 'cancelling registration keeps the checkout proof');
  check(p.checkoutBtn.classList.contains('didit-verified'), 'the checkout button stays verified after registration is cancelled');
  check(!p.regBtn.disabled && p.regField() === '', 'the cancelled registration button can start again');
}
{
  const p = page();
  await verify(p, p.regBtn, S2);
  p.replies.push({ url: 'https://verify.test/x', sessionId: S1 });
  p.click(p.checkoutBtn);
  await flush(); await flush();
  p.complete({ type: 'cancelled' });
  check(p.regField() === S2, 'cancelling checkout keeps the registration proof');
}

// A pending (in review) result belongs to its own button.
{
  const p = page();
  p.replies.push({ url: 'https://verify.test/1', sessionId: S1 });
  p.click(p.checkoutBtn);
  await flush(); await flush();
  p.replies.push({ status: 'In Review', sessionId: S1 });
  p.complete({ type: 'completed', session: { sessionId: S1 } });
  await flush(); await flush();
  await verify(p, p.regBtn, S2);
  p.replies.push({ status: 'Approved', sessionId: S1 });
  p.click(p.checkoutBtn);
  await flush(); await flush();
  const last = p.requests[p.requests.length - 1];
  check(last.url.endsWith('/verify') && last.body.sessionId === S1, 'checking checkout status later confirms the checkout session, not a new one');
  check(p.checkoutField() === S1 && p.regField() === S2, 'both placements keep their proof after the in-review checkout is approved');
}

// Redirect: the return is confirmed on the button that started it.
{
  const p = page({ display: 'redirect' });
  p.replies.push({ url: 'https://verify.test/r', sessionId: S2 });
  p.click(p.regBtn);
  await flush(); await flush();
  check(p.window.assigned === 'https://verify.test/r', 'redirect mode leaves for the hosted page');
  const stored = JSON.parse(p.store.diditPendingSessionId);
  check(stored.id === S2 && stored.placement === 'registration', 'the redirect remembers the session and its placement');

}
{
  // The reply is queued before the script confirms the return on load.
  const storage = { diditPendingSessionId: JSON.stringify({ id: S2, placement: 'registration' }) };
  const p = page({ url: 'https://shop.test/checkout/?verificationSessionId=' + S2 + '&status=Approved', storage, display: 'redirect', queued: [{ status: 'Approved', sessionId: S2 }] });
  await flush(); await flush(); await flush();
  check(p.requests.length === 1 && p.requests[0].url.endsWith('/verify') && p.requests[0].body.sessionId === S2, 'the return confirms the session it came back with');
  check(p.regField() === S2, 'a redirect return for the second button fills the registration field');
  check(p.checkoutField() === '' && !p.checkoutBtn.classList.contains('didit-verified'), 'a redirect return for registration leaves checkout untouched');
  check(p.regBtn.classList.contains('didit-verified'), 'the registration button shows verified after the return');
  check(!('diditPendingSessionId' in p.store), 'the stored return is cleared after confirmation');
}
{
  const storage = { diditPendingSessionId: S1 };
  const p = page({ url: 'https://shop.test/checkout/?verificationSessionId=' + S1, storage, display: 'redirect', queued: [{ status: 'Approved', sessionId: S1 }] });
  await flush(); await flush(); await flush();
  check(p.checkoutField() === S1, 'a bare session ID stored by an earlier version still returns to the first button');
}


console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
