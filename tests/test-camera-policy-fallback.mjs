/**
 * A checkout page whose Permissions-Policy header blocks the camera must not
 * open the verification inside a frame.
 *
 * dampfelix.ch sends `Permissions-Policy: geolocation=(), microphone=(), camera=()`.
 * Chrome then denies getUserMedia inside the SDK frame within milliseconds and
 * without a prompt, whatever the frame's allow attribute says, so Android
 * shoppers stop at the liveness step. Safari does not enforce the header, so
 * iPhones pass. The hosted page opened at the top level is not bound by the
 * shop's header, so the plugin has to send the shopper there instead.
 *
 * Runs the real assets/js/didit-verify.js against a minimal browser stub.
 *
 *   node tests/test-camera-policy-fallback.mjs
 */
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const source = fs.readFileSync(path.join(here, '..', 'assets', 'js', 'didit-verify.js'), 'utf8');

const HOSTED = 'https://verify.didit.me/session/synthetic-test-only';
const SESSION = '00000000-0000-4000-8000-000000000001';
const PAGE = 'https://shop.example/checkout/';

let passed = 0;
let failed = 0;
function ok(condition, label) {
  if (condition) {
    passed++;
    console.log(`  PASS  ${label}`);
  } else {
    failed++;
    console.log(`  FAIL  ${label}`);
  }
}

function element(attrs) {
  const classes = new Set(attrs.className ? attrs.className.split(' ') : []);
  return {
    dataset: { ...(attrs.dataset || {}) },
    textContent: attrs.textContent || '',
    disabled: false,
    innerHTML: '',
    parentNode: { querySelector: () => null },
    classList: {
      contains: (c) => classes.has(c),
      add: (...c) => c.forEach((x) => classes.add(x)),
      remove: (...c) => c.forEach((x) => classes.delete(x)),
      toggle: (c, on) => (on ? classes.add(c) : classes.delete(c)),
    },
    closest(selector) { return selector === '.didit-verify-btn' ? this : null; },
    insertAdjacentElement() {},
    setAttribute() {},
  };
}

/**
 * featurePolicy: what Chrome's document.featurePolicy reports for the camera,
 * or undefined for a browser without the API (Safari, Firefox).
 */
async function run({ cameraAllowed, dataset }) {
  const button = element({ className: 'didit-verify-btn', dataset: { wc: '1', text: 'Verify Identity', ...dataset } });
  const container = element({});
  const listeners = {};
  const requests = [];
  const navigations = [];
  const frames = [];
  const stored = {};

  const document = {
    readyState: 'complete',
    addEventListener: (type, fn) => { listeners[type] = fn; },
    querySelector: (s) => (s === '.didit-verify-btn' ? button : null),
    getElementById: (id) => (id === 'didit-embed' ? container : null),
    createElement: () => element({}),
    dispatchEvent() {},
  };
  if (cameraAllowed !== undefined) {
    document.featurePolicy = {
      allowsFeature: (feature) => (feature === 'camera' || feature === 'microphone' ? cameraAllowed : true),
    };
  }

  const window = {
    diditConfig: {
      mode: 'api', restUrl: 'https://shop.example/wp-json/didit/v1/session', nonce: 'n', i18n: {},
      sendBilling: false, showCloseButton: true, showExitConfirmation: false, closeModalOnComplete: false, loggingEnabled: false,
    },
    DiditSDK: { DiditSdk: { shared: { startVerification: (opts) => frames.push(opts) } } },
    location: { href: PAGE, assign: (url) => navigations.push(url) },
    sessionStorage: { setItem: (k, v) => { stored[k] = v; }, getItem: (k) => stored[k] ?? null, removeItem: (k) => { delete stored[k]; } },
    history: { replaceState() {} },
  };
  window.document = document;

  const fetch = (url, init) => {
    requests.push({ url, body: JSON.parse(init.body || '{}') });
    return Promise.resolve({ ok: true, json: () => Promise.resolve({ url: HOSTED, sessionId: SESSION }) });
  };

  vm.runInNewContext(source, {
    window, document, fetch, URL, console,
    CustomEvent: class { constructor(type, init) { this.type = type; this.detail = init && init.detail; } },
  });

  listeners.click({ target: button, preventDefault() {} });
  for (let i = 0; i < 10; i++) await new Promise((r) => setImmediate(r));
  return { requests, navigations, frames, stored };
}

for (const [label, dataset] of [
  ['modal', { display: 'modal' }],
  ['embedded', { display: 'embedded', container: 'didit-embed' }],
]) {
  console.log(`\nCheckout header blocks the camera (camera=()), ${label} button`);
  const r = await run({ cameraAllowed: false, dataset });
  ok(r.frames.length === 0, 'does not open the verification in a frame the camera cannot reach');
  ok(r.navigations.length === 1 && r.navigations[0] === HOSTED, 'sends the shopper to the hosted page at the top level');
  ok(r.requests[0] && r.requests[0].body.return_url === PAGE, 'asks Didit to return the shopper to this checkout page');
  ok(Object.values(r.stored).includes(SESSION), 'remembers the session so the result is confirmed on return');

  console.log(`\nCheckout allows the camera, ${label} button`);
  const allowed = await run({ cameraAllowed: true, dataset });
  ok(allowed.frames.length === 1 && allowed.frames[0].url === HOSTED, `still opens the ${label} frame`);
  ok(allowed.navigations.length === 0, 'does not leave the page');

  console.log(`\nBrowser without the policy API (Safari), ${label} button`);
  const safari = await run({ cameraAllowed: undefined, dataset });
  ok(safari.frames.length === 1 && safari.navigations.length === 0, `still opens the ${label} frame`);
}

console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed > 0 ? 1 : 0);
