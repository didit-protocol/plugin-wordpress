=== Didit Verify ===
Contributors: alexdidit
Tags: identity verification, kyc, woocommerce, age verification, id check
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.3.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add identity verification to any WordPress page or WooCommerce checkout using Didit.

== Description ==

Didit Verify lets you require identity verification on your WordPress site. Drop a shortcode on any page or require it at WooCommerce checkout.

**Two integration modes:**

* **UniLink** — paste a URL from the workflow you want from Didit Console. No backend needed.
* **API Session** — [RECOMMENDED] the plugin creates a unique session per user. Your API key stays server-side.

**Display options:**

* **Modal** — opens a centered overlay on top of the page
* **Embedded** — renders the verification inline where the shortcode is placed
* Configurable close button, exit confirmation dialog, and auto-close on completion
* Debug logging for SDK events in the browser console

**Button appearance:**

* Fully configurable from the admin panel: text, colors, border radius, padding, font size
* Live preview in Settings that updates as you change values
* Shortcode attributes can override the button text per page

**Content gating:**

* `[didit_gate]` shortcode — restrict any content to verified users only
* `[didit_status]` shortcode — show the user's verification status anywhere
* Verification status saved to WordPress user meta and visible in the admin Users list

**WooCommerce support:**

* Require verification at checkout with 4 position options
* Product scope: require verification for all products, only selected products, or all products except selected — with a per-product checkbox in the product editor
* Customizable checkout copy: section title, checkout message, and post-purchase message editable from settings
* Automatically send billing data (name, email, phone, address) to Didit for pre-filling and cross-validation
* Verification session ID saved to order meta for audit

**Translations:**

* All customer-facing text is translatable (text domain `didit-verify`, POT file included)
* German translations included: `de_DE` (informal) and `de_DE_formal` (formal)

**Developer extensibility:**

* PHP action hooks: `didit_session_created`, `didit_verification_completed`, `didit_verification_cancelled`
* PHP filter: `didit_sdk_url` to change the SDK CDN
* DOM CustomEvent: `didit:complete` for frontend JavaScript

**Security (API mode):**

* API key stored server-side only — never sent to the browser
* CSRF nonce on every request
* Per-user rate limit: 10 sessions/hour
* Per-IP rate limit: 3 sessions/hour (guests)
* All input whitelisted and sanitized

== Third-Party Service ==

This plugin connects to the [Didit](https://didit.me) identity verification service to process user verifications. When a verification session is created (API mode), the plugin sends data to Didit's servers. When the verification UI is displayed, an iframe loads content from `verify.didit.me`.

This plugin bundles the [Didit Web SDK](https://www.npmjs.com/package/@didit-protocol/sdk-web) (version 0.2.1) as `assets/js/didit-sdk.umd.min.js`. The full unminified source code is publicly available at the GitHub repository and npm package linked below.

* Service: [https://didit.me](https://didit.me)
* SDK source code: [https://github.com/didit-protocol/sdk-web](https://github.com/didit-protocol/sdk-web)
* SDK npm package: [https://www.npmjs.com/package/@didit-protocol/sdk-web](https://www.npmjs.com/package/@didit-protocol/sdk-web)
* SDK license: MIT
* Terms of Use: [https://didit.me/en/terms/identity-verification/](https://didit.me/en/terms/identity-verification/)
* Privacy Policy: [https://didit.me/en/terms/privacy-policy/](https://didit.me/en/terms/privacy-policy/)

The SDK can be rebuilt from source with `npm install && npm run build` (uses Rollup). See the GitHub repository for full build instructions.

No data is sent to Didit until the site administrator configures the plugin and a user initiates verification.

== Installation ==

1. Upload the `didit-verify` folder to `/wp-content/plugins/`
2. Activate the plugin through **Plugins → Didit Verify**
3. Go to **Settings → Didit Verify** and configure your mode:

**UniLink (simplest):**
Enter the UniLink URL from your [Didit Console](https://business.didit.me) workflow.

**API Session (recommended):**
Enter your Workflow ID and API Key from the [Didit Console](https://business.didit.me).

4. Add `[didit_verify]` to any page or post.

== Frequently Asked Questions ==

= Where do I get a Workflow ID and API Key? =

Sign up at [business.didit.me](https://business.didit.me), create a verification workflow and copy the Workflow ID. Then go to API & Webhooks and copy your API Key.

= What is the difference between UniLink and API mode? =

UniLink uses a single shared URL — quick to set up but every visitor uses the same session link. API mode creates a unique verification session per user with full tracking, and keeps your API key secure on the server.

= Does it work without WooCommerce? =

Yes. Use the `[didit_verify]` shortcode on any page. WooCommerce integration is optional.

= Is my API key safe? =

Yes. In API mode, the key is stored in the WordPress database and used only in server-to-server calls. It is never included in any HTML or JavaScript sent to the browser.

= What is vendor data? =

Vendor data identifies each user in your Didit dashboard. The plugin automatically sends a per-user value based on your chosen mode:

* **WordPress User ID** (default) — sends `wp-42`
* **User Email** — sends the user's email address
* **Custom prefix + User ID** — sends e.g. `mystore-42`
* **None** — omits the field

This enables session tracking and aggregation across multiple verifications for the same user.

= How do I restrict content to verified users? =

Wrap any content with the `[didit_gate]` shortcode:

`[didit_gate]This content is only for verified users.[/didit_gate]`

Unverified users see a message and a verification button. Once verified, the content is revealed. You can customize the message:

`[didit_gate message="Please verify to continue."]Secret content here.[/didit_gate]`

= How do I show verification status? =

Use the `[didit_status]` shortcode. It shows "Identity Verified" or "Not Verified" for the logged-in user. You can customize all labels:

`[didit_status verified_text="Verified!" unverified_text="Pending" login_text="Sign in first"]`

= How do I check if a user is verified in PHP? =

`get_user_meta($user_id, '_didit_verified', true)` returns `1` if verified. You can also check `_didit_status` for the result (Approved, Pending, Declined).

= Can I hook into verification events? =

Yes. The plugin fires WordPress actions:

* `didit_verification_completed` — when a user completes verification (passes user ID, session ID, status)
* `didit_verification_cancelled` — when a user cancels
* `didit_session_created` — when a session is created server-side

Use `add_action()` in your theme's `functions.php` to hook in.

= Can I customize the button? =

Go to **Settings → Didit Verify → Button Appearance**. You can change:

* Button text and success text
* Background color and text color
* Border radius (0 = square, 50 = pill)
* Padding (vertical and horizontal)
* Font size

A live preview updates in real time as you change values. You can also override the text per page:

`[didit_verify text="Verify Now" success_text="Done!"]`

The button has CSS class `didit-verify-btn` (and `didit-verified` after success) for further styling.

= Can I change or translate the checkout page text? =

Yes, both ways:

* **Change it**: go to **Settings → Didit Verify → WooCommerce** and set **Section Title**, **Checkout Message**, and **Post-purchase Message**. Empty fields use the default text.
* **Translate it**: the plugin ships with German translations (`de_DE` and `de_DE_formal`) and a POT file (`languages/didit-verify.pot`) for other languages. Default texts automatically follow the site language.

= Can I require verification only for certain products? =

Yes. Go to **Settings → Didit Verify → WooCommerce → Product Scope** and choose:

* **All products** — verification applies to every order (default)
* **Only selected products** — verification is required only when the cart contains a selected product
* **All products except selected** — verification is skipped only when every product in the cart is selected

Select products on the product edit page under **Product data → Advanced → Didit verification**. Mixed carts require verification whenever at least one product in the cart requires it. The scope applies to both checkout and after-purchase modes.

= Can I switch between modal and embedded display? =

Yes. Go to **Settings → Didit Verify → Display Options → Display Mode**. Choose Modal (popup overlay) or Embedded (inline where the shortcode is). You can also override per shortcode:

`[didit_verify mode="embedded"]`

== Screenshots ==

1. Settings page — configure mode, credentials, display options, and button appearance.
2. Button Appearance section with live preview.
3. Verification button on a page.
4. WooCommerce checkout with verification step.

== Changelog ==

= 0.3.1 =
* Page builders that render shortcode content outside the post content (for example Oxygen) no longer show an unstyled verification button or embedded container. The plugin stylesheet and the button appearance CSS are now always queued in the header, while the SDK script is still enqueued when the shortcode itself renders.
* Webhook receiver now verifies `X-Signature-V2` first, falls back to the legacy `X-Signature`, and finally to `X-Signature-Simple`. A delivery is accepted as soon as one variant verifies, so a reverse proxy, CDN rule or security plugin that strips a single `X-*` header no longer causes a 401 "Missing or stale webhook signature".
* Middleware that re-encodes the request body (Unicode escaping, slash escaping, key reordering) no longer breaks verification: `X-Signature-V2` is computed over the canonical JSON form rather than the raw bytes.
* Freshness is now checked against the signed `timestamp` inside the payload, which also rejects a replayed delivery re-sent with a refreshed `X-Timestamp` header.
* When only `X-Signature-Simple` verifies, the payload is authenticated for `session_id`, `status` and `webhook_type` only, so the user is resolved from the session mapping this site stored itself instead of the unsigned `metadata.wp_user_id` / `vendor_data` fields.
* Enabling Debug Logging now records which signature headers arrived when a webhook is rejected.

= 0.3.0 =
* Customizable WooCommerce copy: new settings for the verification section title, checkout message, and post-purchase message (GitHub issue #2).
* Translation support: load the plugin text domain from `languages/`, POT file included, and German translations (`de_DE` informal, `de_DE_formal` formal) for all customer-facing text (GitHub issue #2).
* Frontend JavaScript strings ("Creating session…", "Verification In Review", error messages) are now translatable.
* Product scope for WooCommerce verification: require verification for all products, only selected products, or all products except selected (GitHub issue #3).
* New "Didit verification" checkbox on the product edit page (Product data → Advanced) to select products for the include/exclude scope.
* Product scope is enforced in classic checkout, block checkout, and after-purchase mode (confirmation box, emails, reminders, order hold).
* Button text and success text settings now fall back to translatable defaults when left empty.

= 0.2.0 =
* New WooCommerce verification mode: "After purchase" — keep checkout low-barrier and ask for verification on the order confirmation page, My Account order view, and order emails (GitHub issue #1).
* Guest customers can now verify after purchase: the order key authenticates the session and result endpoints, no login required.
* Webhook receiver (didit/v1/webhook) with HMAC-SHA256 signature and timestamp validation — verification results update orders even if the customer closes the browser.
* Optional "Hold orders" setting: orders stay On hold until the Didit webhook confirms approval, then move to Processing automatically.
* Optional reminder emails (configurable interval and cap) for customers who have not completed verification, scheduled via Action Scheduler.
* After-purchase sessions are created server-side from order billing data and reused across clicks/reloads to avoid duplicates.
* Verification status is now shown next to the session id in the admin order screen.
* Update Didit Web SDK to version 0.2.1.

= 0.1.4 =
* Match verification status handling like in woocommerce: only grant access for Approved status.
* Differentiate Approved, Declined, and In Review states in button, content gate, status shortcode, and admin users list.
* Use Didit design system colors for status indicators.

= 0.1.3 =
* Update Didit Web SDK to version 0.1.8.
* Improved support for Woocommerce block based checkout on new versions.

= 0.1.2 =
* Update Didit Web SDK to version 0.1.6.
* Document bundled SDK source code repository, license, and build instructions in readme.
* Move admin inline scripts to enqueued JavaScript file.
* Fix contributors username.

= 0.1.1 =
* Bundle SDK JavaScript locally instead of loading from CDN.
* Add third-party service disclosure (Didit Terms of Use and Privacy Policy).
* Add all 49 supported languages to the language selector.
* Fix Plugin Check (PCP) errors: output escaping, translators comment, variable prefixes.
* Wrap debug logging behind WP_DEBUG flag.

= 0.1.0 =
* Initial release.
* UniLink and API Session modes.
* Modal and embedded display modes.
* Configurable button appearance with live admin preview (colors, radius, padding, font size).
* Content gating with `[didit_gate]` shortcode.
* Verification status shortcode `[didit_status]`.
* Verification status saved to WordPress user meta.
* Verification column in admin Users list.
* WooCommerce checkout integration with 4 position options and billing data forwarding.
* Dynamic vendor data (User ID, email, custom prefix, or none).
* PHP action hooks and `didit_sdk_url` filter for developer extensibility.
* 6-layer security model for API session creation.
* 49 language options for the verification UI.

== Upgrade Notice ==

= 0.3.0 =
Customizable checkout copy, German translations, and per-product verification scope for WooCommerce.

= 0.1.4 =
Match verification status handling between woocommerce and wordpress

= 0.1.3 =
SDK updated to 0.1.8. Improved support for Woocommerce block based checkout on new versions.

= 0.1.2 =
SDK updated to 0.1.6, source code documented, admin scripts properly enqueued.

= 0.1.1 =
SDK bundled locally, third-party disclosure, all 49 languages, Plugin Check compliance.

= 0.1.0 =
First release.
