# Didit Verify — WordPress Plugin

Identity verification for WordPress & WooCommerce using the [Didit SDK](https://didit.me).

## What it does

| Feature | Description |
|---------|-------------|
| **Shortcode** | `[didit_verify]` — drop a verification button on any page |
| **WooCommerce** | Require identity verification at checkout |
| **Two modes** | **UniLink** (no backend, paste a URL) or **API** (unique sessions per user) |
| **Display** | Modal (popup overlay) or Embedded (inline) |
| **Customizable** | Button colors, text, padding, radius — all configurable with live preview. Checkout section title and messages editable too |
| **Translatable** | All customer-facing text is translatable; German (`de_DE`, `de_DE_formal`) included |
| **Product scope** | Require verification for all products, only selected products, or all except selected |
| **Secure** | API key stays server-side; CSRF nonce + rate limiting on session endpoint |

## Verification decisions

Use **API Session** mode for content gating and WooCommerce checkout.
UniLink is for launching a flow and reviewing the result manually in Didit.
The server binds each created session to its visitor or order, and retrieves the Didit decision before accepting a browser completion or checkout request.
Only `Approved` grants access; errors, `In Review`, `Declined`, expired sessions and unrelated IDs do not.
Signed webhooks update only sessions created by this site.
After upgrading from 0.3.1 or earlier, existing users need to verify again because earlier browser-reported approvals are not trusted.

## Third-Party Service

This plugin connects to the [Didit](https://didit.me) identity verification service. When a verification session is created (API mode), the plugin sends data to Didit's servers. The verification UI loads in an iframe from `verify.didit.me`.

- Service: [https://didit.me](https://didit.me)
- Terms of Use: [https://didit.me/en/terms/identity-verification/](https://didit.me/en/terms/identity-verification/)
- Privacy Policy: [https://didit.me/en/terms/privacy-policy/](https://didit.me/en/terms/privacy-policy/)

No data is sent until the administrator configures credentials and a user initiates verification.

## Quick Start

### 1. Start the dev environment

```bash
cd wordpress-plugin
docker compose up -d
```

Open <http://localhost:8080> and complete the WordPress setup wizard.

### 2. Activate the plugin

**Plugins → Didit Verify → Activate**

### 3. Configure

**Settings → Didit Verify**

#### UniLink mode (manual review)

1. Set **Mode** to `UniLink`
2. Paste your UniLink URL (from [Didit Console](https://business.didit.me) → Workflow → Copy Link)
3. Save

#### API mode (recommended for production)

1. Set **Mode** to `API Session`
2. Enter your **Workflow ID** and **API Key**
3. Optionally configure **Session Options**:
   - **Vendor Data** — identifies each user in Didit (see below)
   - **Callback URL** — URL to redirect the user after verification (Didit appends `verificationSessionId` and `status` as query params)
   - **Callback Method** — `initiator` (device that started), `completer` (device that finishes), or `both`
   - **Language** — verification UI language (auto-detect or pick from 49 options)
4. Save

> In API mode the plugin creates a unique verification session per user. The API key is stored on the server and **never** sent to the browser.

### 4. Display Options

Configure how the verification UI appears:

| Setting | Description |
|---------|-------------|
| **Display Mode** | Modal (popup overlay) or Embedded (inline where shortcode is placed) |
| **Close Button** | Show or hide the X button on the modal |
| **Exit Confirmation** | "Are you sure?" dialog when closing the modal |
| **Auto-close** | Automatically close the modal when verification completes |
| **Debug Logging** | Log SDK events to the browser console (for troubleshooting) |

### 5. Button Appearance

Customize the verification button from **Settings → Didit Verify → Button Appearance**:

| Setting | Default | Description |
|---------|---------|-------------|
| **Button Text** | "Verify your Identity" | Label before verification |
| **Success Text** | "Identity Verified ✓" | Label after verification |
| **Background Color** | `#111111` | Button background |
| **Text Color** | `#ffffff` | Button text |
| **Border Radius** | `999px` | Corner rounding (0 = square, 999 = pill) |
| **Padding** | `12px × 24px` | Vertical × horizontal |
| **Font Size** | `16px` | Button font size |

A **live preview** in the admin panel updates in real time as you change values.

### 6. Add the shortcode

Create a page and add:

```
[didit_verify]
```

This renders a verification button styled with your Button Appearance settings.

**Override text per page:**

```
[didit_verify text="Verify Now" success_text="Done!"]
```

**Override display mode per shortcode:**

```
[didit_verify mode="embedded"]
```

### 7. Status & content gating

**Show verification status** anywhere:

```
[didit_status]
```

Displays "Identity Verified" or "Not Verified" for the logged-in user. You can customize all labels:

```
[didit_status verified_text="Verified!" unverified_text="Pending" login_text="Sign in first"]
```

**Restrict content** to verified users only:

```
[didit_gate]This content is only visible to verified users.[/didit_gate]
```

Unverified users see a message and a verification button. You can customize the message:

```
[didit_gate message="Please verify to continue."]Secret content here.[/didit_gate]
```

### 8. WooCommerce (optional)

Choose a **Verification Mode** in settings:

| Mode | Behavior |
|------|----------|
| **Off** | No verification in WooCommerce |
| **Require at checkout** | The order cannot be placed until the customer verifies (blocks "Place Order") |
| **After purchase** | Low-barrier checkout — the customer verifies on the order confirmation page |

#### Product scope

Choose which products trigger verification (**Settings → Didit Verify → WooCommerce → Product Scope**):

| Scope | Behavior |
|-------|----------|
| **All products** (default) | Verification applies to every order |
| **Only selected products** | Verification is required only when the cart contains a selected product |
| **All products except selected** | Verification is skipped only when every product in the cart is selected |

Select products on the product edit page under **Product data → Advanced → Didit verification**.
Mixed carts require verification whenever at least one product in the cart requires it.
The scope applies to both checkout and after-purchase modes (including confirmation box, order emails, reminders, and order hold).

#### Checkout copy

The verification section text is editable from settings (**Section Title**, **Checkout Message**, **Post-purchase Message**).
Fields left empty use the default text, which follows the site language — German translations (`de_DE` informal, `de_DE_formal` formal) ship with the plugin, and `languages/didit-verify.pot` is included for other languages.

#### Require at checkout

1. Choose a **Position** for the verification section:
   - Top of checkout page
   - After billing details
   - After order notes
   - Before "Place Order" (recommended)
2. Check **Send Billing Data** (enabled by default) — automatically sends the customer's billing info to Didit:
   - **contact_details**: email, phone
   - **expected_details**: first_name, last_name, country, full address
   - Country codes are automatically converted from WooCommerce alpha-2 to Didit's alpha-3 format

#### After purchase

The verification box appears on the **order confirmation page** (classic and block themes), the **My Account → order view**, and a verification link is added to **customer order emails**. Works for **guest customers** too — the order key authenticates the request, no login needed.

- Sessions are created server-side from the order's billing data and **reused** across clicks/reloads (no duplicate sessions, no wasted rate limit).
- **Hold Orders** (optional): orders stay **On hold** until Didit confirms approval through the decision API or a signed webhook, then move to **Processing** automatically. Requires the Webhook Secret to be configured — the browser alone never releases a held order.
- **Reminders** (optional): email customers who haven't verified, every N days, capped at a maximum per order. Scheduled with Action Scheduler (ships with WooCommerce). Reminders stop on approval/decline or when the order is cancelled/refunded.

#### Webhooks (recommended)

Configure a webhook destination in the [Didit Business Console](https://business.didit.me) (API & Webhooks) pointing to:

```
https://your-store.com/wp-json/didit/v1/webhook
```

Paste the destination's secret into **Settings → Didit Verify → Webhook Secret**.
The receiver is idempotent on duplicate deliveries and updates **all** orders sharing the session as well as the customer's user meta.
This is the authoritative completion channel - it works even if the customer closes the browser mid-flow.

##### Signature verification

Didit signs every delivery three ways and sends all three headers.
The receiver tries them in order and accepts the delivery as soon as one verifies, so a single stripped or invalidated header is not enough to lock the site out:

| # | Header | Signed over | Survives |
|---|--------|-------------|----------|
| 1 | `X-Signature-V2` | Canonical JSON re-encoding of the body (recursively sorted keys, compact separators, slashes and Unicode unescaped) | Middleware that re-encodes the body |
| 2 | `X-Signature` | The exact bytes Didit transmitted | Nothing between Didit and PHP rewriting the body |
| 3 | `X-Signature-Simple` | `"{timestamp}:{session_id}:{status}:{webhook_type}"` | Anything - but it authenticates the **envelope only** |

Freshness is checked against the `timestamp` field inside the payload, which every variant signs, with the `X-Timestamp` header as a fallback.
Deliveries older than 5 minutes are rejected, and a replay re-sent with a refreshed header is rejected too.

When only `X-Signature-Simple` verifies, the rest of the payload is unauthenticated, so the receiver ignores `metadata.wp_user_id` and `vendor_data` and resolves the WordPress user from the session mapping this site stored itself.
Orders are always matched on the stored `_didit_session_id` meta, which is unaffected.

If verification fails, enable **Debug Logging** to record which signature headers actually reached PHP - that is usually enough to identify the proxy or security plugin dropping them.

> `X-Signature-V2` needs PHP's `serialize_precision` at its default `-1`.
> A host that sets it to `17` writes `87.42` as `87.420000000000002`, and V2 will never match - the legacy fallback still works in that case.

In both modes the session ID is saved to order meta (`_didit_session_id`) and shown with its verification status in the admin order screen.

### Vendor Data (user identifier)

The **Vendor Data** field tells Didit which user each verification belongs to, enabling session aggregation in your Didit dashboard. Choose a mode in the admin settings:

| Mode | Value sent | Example |
|------|-----------|---------|
| **WordPress User ID** (default) | `wp-{id}` | `wp-42` |
| **User Email** | user's email | `john@example.com` |
| **Custom prefix + User ID** | `{prefix}{id}` | `mystore-42` |
| **None** | (omitted) | — |

For guest users (when "Require Login" is off), the plugin falls back to `guest-{ip_hash}`.

### Data sent to Didit (API mode)

When creating a session, the plugin sends:

| Field | Source | Description |
|-------|--------|-------------|
| `workflow_id` | Admin settings | Your workflow ID |
| `vendor_data` | Auto (per-user) | User identifier for session tracking (see above) |
| `callback` | Admin settings | Redirect URL after verification |
| `callback_method` | Admin settings | `initiator`, `completer`, or `both` |
| `language` | Admin settings | Verification UI language (ISO 639-1) |
| `contact_details` | WC checkout form | Customer email & phone |
| `expected_details` | WC checkout form | Name, country, address |
| `portrait_image` | Frontend (optional) | Base64 face image for cross-referencing |
| `metadata` | Auto | WordPress user ID, email, IP (server-injected, cannot be overwritten) |

All data is sanitized server-side before being sent to the Didit API.

## Shortcode Reference

### `[didit_verify]`

| Attribute | Default | Description |
|-----------|---------|-------------|
| `text` | Admin setting | Button label |
| `success_text` | Admin setting | Label after verification |
| `mode` | Admin setting | `modal` or `embedded` — override display mode |

### `[didit_status]`

| Attribute | Default | Description |
|-----------|---------|-------------|
| `verified_text` | "Identity Verified" | Text for verified users |
| `unverified_text` | "Not Verified" | Text for unverified users |
| `login_text` | "Please log in" | Text for logged-out visitors |

### `[didit_gate]`

| Attribute | Default | Description |
|-----------|---------|-------------|
| `message` | "Please verify your identity to access this content." | Message shown to unverified users |

## File Structure

```
wordpress-plugin/
├── didit-verify.php              # Plugin logic (admin, REST API, shortcode, WC)
├── assets/
│   ├── css/didit-verify.css      # Structural styles (embed container)
│   └── js/didit-verify.js        # Frontend SDK integration
├── languages/                    # POT template + German translations (de_DE, de_DE_formal)
├── uninstall.php                 # Cleans up options on plugin deletion
├── readme.txt                    # WordPress.org plugin directory format
├── tests/                         # Webhook signature tests (plain PHP, no dependencies)
├── docker-compose.yml            # Local dev (WordPress + MySQL)
└── README.md
```

## How It Works

### UniLink Flow

```
User clicks button → JS calls DiditSdk.startVerification({ url }) → Modal opens
→ User completes verification → onComplete fires → Show submitted message for manual review
```

### API Flow

```
User clicks button
→ JS sends POST /wp-json/didit/v1/session with:
    X-WP-Nonce (CSRF)  +  billing data (if WC checkout)
→ PHP checks: nonce ✓ → login ✓ → rate limit ✓
→ PHP calls Didit API with API key (server-side) → binds session to visitor → returns { url, sessionId }
→ JS calls DiditSdk.startVerification({ url }) → modal opens
→ User completes → onComplete fires → JS requests server confirmation
→ PHP checks the binding and retrieves the Didit decision
→ Only a server-confirmed Approved decision shows "Verified"
```

### WooCommerce Flow

```
Same as above, plus:
→ Billing data (name, email, phone, address) auto-sent as expected_details
→ Country code converted from alpha-2 to alpha-3 automatically
→ Session ID written to hidden checkout field
→ On "Place Order", PHP validates ownership and retrieves the current Didit decision
→ Checkout proceeds only for Approved
→ Session ID saved to order meta (_didit_session_id)
→ Visible in admin order screen
```

## Security

The API key stays on the server.
Session creation requires a WordPress REST nonce and applies login settings and rate limits.
Guest checkout and valid after-purchase order links can start verification without a WordPress login.
A nonce is not proof of identity, and expected details from the browser are inputs to verification rather than authorization.

The server records ownership when it creates a session.
Completion requests and checkout validation require that binding and a matching decision retrieved from Didit.
A browser-supplied status, arbitrary session ID or signed webhook metadata cannot assign a session to another visitor or order.
Only a known session and authenticated status update can update protected access.
API outages and unknown decisions fail closed.

## Customization

### Change the SDK CDN URL

```php
add_filter( 'didit_sdk_url', function () {
    return 'https://unpkg.com/@didit-protocol/sdk-web@0.2.1/dist/didit-sdk.umd.min.js';
} );
```

### Style the button via CSS

Button appearance is configured in the admin panel (Settings → Didit Verify → Button Appearance). For additional CSS overrides, the button has the class `didit-verify-btn` and gains `didit-verified` after successful verification:

```css
/* Override admin styles */
.didit-verify-btn {
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
}
.didit-verify-btn:disabled {
    opacity: 0.5;
}
.didit-verify-btn.didit-verified {
    background: #16a34a !important;
}
```

## User Verification Status

When a user completes verification, the plugin saves these fields to WordPress user meta:

| Meta key | Value | Description |
|----------|-------|-------------|
| `_didit_verified` | `1` | User is verified |
| `_didit_session_id` | UUID | Didit session ID |
| `_didit_confirmed_session_id` | UUID | Session whose status was confirmed by the server |
| `_didit_status` | `Approved` / `Pending` / `Declined` | Verification result |
| `_didit_verified_at` | datetime | When verification was completed |

You can query this in PHP:

```php
$is_verified = Didit_Verify::init()->is_user_verified($user_id);
```

A **Didit** column appears in the admin Users list showing a green checkmark for verified users and a dash for unverified.

## Developer Hooks

The plugin fires WordPress actions that other plugins can hook into:

```php
// Fired when a verification session is created (server-side).
add_action('didit_session_created', function ($url, $user_id, $vendor_data) {
    // Log, notify, etc.
}, 10, 3);

// Fired when a user completes verification.
add_action('didit_verification_completed', function ($user_id, $session_id, $status) {
    // Update CRM, send email, grant access, etc.
}, 10, 3);

// Fired when a user cancels verification.
add_action('didit_verification_cancelled', function ($user_id, $session_id) {
    // Log cancellation.
}, 10, 2);
```

A DOM event is also dispatched for frontend JavaScript:

```javascript
document.addEventListener('didit:complete', function (e) {
    console.log('Result:', e.detail); // { type, session }
});
```

## REST API Endpoints

| Method | Endpoint | Description | Auth |
|--------|----------|-------------|------|
| `POST` | `/wp-json/didit/v1/session` | Create a verification session | CSRF nonce + login, or order key (after-purchase) |
| `POST` | `/wp-json/didit/v1/verify` | Confirm the bound session with Didit and update its status | REST nonce and browser/user binding, or a valid order key |
| `POST` | `/wp-json/didit/v1/webhook` | Didit webhook receiver (`status.updated`) | HMAC-SHA256 signature (`X-Signature-V2` → `X-Signature` → `X-Signature-Simple`) + timestamp |

## Uninstall

When the plugin is deleted via the WordPress admin, `uninstall.php` removes all plugin options from the database. The plugin also removes its user verification metadata and session bindings.

## Install WooCommerce (for testing)

```bash
docker compose exec wordpress wp plugin install woocommerce --activate --allow-root
```

## Tests

Webhook signature verification is covered by a dependency-free PHP test suite:

```bash
php tests/test-webhook-signature.php
# or, without a local PHP:
docker run --rm -v "$PWD":/app -w /app php:8.2-cli php tests/test-webhook-signature.php
```

The fixtures in `tests/fixtures/` are generated by `tests/generate-fixtures.py`, which carries Didit's server-side signing code verbatim - so a passing run means PHP reproduces the exact bytes the platform signed, including Unicode, empty objects, escaped slashes, floats, and numeric-looking keys.
Regenerate them with:

```bash
python3 tests/generate-fixtures.py > tests/fixtures/webhook-signatures.json
```

## License

GPL-2.0-or-later — Copyright © 2025 Didit.

## Tests

Run `php tests/test-shortcode-assets.php`, `php tests/test-webhook-signature.php` and `php tests/test-verification-authorization.php`.
The local end-to-end fixture in `tests/e2e/fixture-api.php` intercepts Didit HTTP calls only when `WP_ENVIRONMENT_TYPE` is `local`.
It must never be installed on a production site.
The release was also checked through actual WordPress REST requests, classic WooCommerce checkout and the Store API checkout with synthetic customers and upstream decisions.
