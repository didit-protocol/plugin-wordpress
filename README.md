# Didit Verify — WordPress Plugin

Identity verification for WordPress & WooCommerce using the [Didit SDK](https://didit.me).

## What it does

| Feature | Description |
|---------|-------------|
| **Shortcode** | `[didit_verify]` — drop a verification button on any page |
| **WooCommerce** | Require identity verification at checkout |
| **Two modes** | **UniLink** (no backend, paste a URL) or **API** (unique sessions per user) |
| **Display** | Modal (popup overlay) or Embedded (inline) |
| **Customizable** | Button colors, text, padding, radius — all configurable with live preview |
| **Secure** | API key stays server-side; CSRF nonce + rate limiting on session endpoint |

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

#### UniLink mode (simplest)

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
| **Background Color** | `#2667ff` | Button background |
| **Text Color** | `#ffffff` | Button text |
| **Border Radius** | `8px` | Corner rounding (0 = square, 50 = pill) |
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

1. Check **Require identity verification at checkout** in settings
2. Choose a **Position** for the verification section:
   - Top of checkout page
   - After billing details
   - After order notes
   - Before "Place Order" (recommended)
3. Check **Send Billing Data** (enabled by default) — automatically sends the customer's billing info to Didit:
   - **contact_details**: email, phone
   - **expected_details**: first_name, last_name, country, full address
   - Country codes are automatically converted from WooCommerce alpha-2 to Didit's alpha-3 format

The session ID is saved to order meta (`_didit_session_id`) and visible in the admin order screen.

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
├── uninstall.php                 # Cleans up options on plugin deletion
├── readme.txt                    # WordPress.org plugin directory format
├── docker-compose.yml            # Local dev (WordPress + MySQL)
└── README.md
```

## How It Works

### UniLink Flow

```
User clicks button → JS calls DiditSdk.startVerification({ url }) → Modal opens
→ User completes verification → onComplete fires → Button shows "Verified"
```

### API Flow

```
User clicks button
→ JS sends POST /wp-json/didit/v1/session with:
    X-WP-Nonce (CSRF)  +  billing data (if WC checkout)
→ PHP checks: nonce ✓ → login ✓ → rate limit ✓
→ PHP calls Didit API with API key (server-side) → returns { url }
→ JS calls DiditSdk.startVerification({ url }) → modal opens
→ User completes → onComplete fires → button shows "Verified"
→ JS sends POST /wp-json/didit/v1/verify → saves result to user meta
```

### WooCommerce Flow

```
Same as above, plus:
→ Billing data (name, email, phone, address) auto-sent as expected_details
→ Country code converted from alpha-2 to alpha-3 automatically
→ Session ID written to hidden checkout field
→ On "Place Order", PHP validates the field is not empty
→ Session ID saved to order meta (_didit_session_id)
→ Visible in admin order screen
```

## Security

The plugin acts as a **secure backend proxy** between the browser and the Didit API. A hacker cannot create sessions directly — every request goes through multiple security layers:

| # | Layer | What it prevents |
|---|-------|-----------------|
| 1 | **CSRF nonce** | Cross-site request forgery. Request must originate from a page served by WordPress. |
| 2 | **Require login** | Anonymous abuse. Only registered WordPress users can create sessions (configurable, **ON by default**). |
| 3 | **Per-user rate limit** | Logged-in users: max **10 sessions/hour** per user. |
| 4 | **Per-IP rate limit** | Guests (if login not required): max **3 sessions/hour** per IP. |
| 5 | **API key server-only** | Key extraction. The Didit API key is in `wp_options` (database), never in HTML/JS. |
| 6 | **Input sanitization** | Injection. All fields are whitelisted and sanitized. Metadata from the server (`wp_user_id`, `wp_ip`) can never be overwritten by the frontend. |

### Why `expected_details` from the frontend is safe

The `expected_details` (name, country, address) are sent from the checkout form. A hacker could theoretically modify them — but this **only hurts themselves**: Didit compares these against the real identity document. Fake expected details → verification **fails**. They can't use this to pass verification.

### Attack scenario analysis

| Attack | Protection |
|--------|-----------|
| Hacker scripts mass session creation from another site | **Layer 1**: nonce rejected (wrong origin) |
| Hacker scripts mass creation from the WordPress site | **Layer 3/4**: rate limited to 10/hour per user, 3/hour per IP for guests. |
| Hacker creates a bot that reloads + creates sessions | **Layer 2**: must be logged in. **Layer 3**: 10/hour max per user. **Layer 4**: 3/hour per IP if guest. |
| Hacker sends fake billing data | **Safe**: only hurts their own verification (document won't match). |
| Hacker tries to extract the API key | **Layer 5**: key is only in the database, never in any response or HTML. |

## Customization

### Change the SDK CDN URL

```php
add_filter( 'didit_sdk_url', function () {
    return 'https://unpkg.com/@didit-protocol/sdk-web@0.1.5/dist/didit-sdk.umd.min.js';
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
| `_didit_status` | `Approved` / `Pending` / `Declined` | Verification result |
| `_didit_verified_at` | datetime | When verification was completed |

You can query this in PHP:

```php
$is_verified = get_user_meta($user_id, '_didit_verified', true);
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
| `POST` | `/wp-json/didit/v1/session` | Create a verification session | CSRF nonce + optional login |
| `POST` | `/wp-json/didit/v1/verify` | Save verification result to user meta | Login required |

## Uninstall

When the plugin is deleted via the WordPress admin, `uninstall.php` removes all plugin options from the database. User meta (`_didit_verified`, etc.) is preserved so verification status is not lost.

## Install WooCommerce (for testing)

```bash
docker compose exec wordpress wp plugin install woocommerce --activate --allow-root
```

## License

GPL-2.0-or-later — Copyright © 2025 Didit.
