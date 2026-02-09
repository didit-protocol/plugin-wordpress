=== Didit Verify ===
Contributors: didit
Tags: identity verification, kyc, woocommerce, age verification, id check
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.1.0
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
* Automatically send billing data (name, email, phone, address) to Didit for pre-filling and cross-validation
* Verification session ID saved to order meta for audit

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

Sign up at [business.didit.me](https://business.didit.me), create a verification workflow, and copy the Workflow ID and API Key from the workflow settings.

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

= Can I switch between modal and embedded display? =

Yes. Go to **Settings → Didit Verify → Display Options → Display Mode**. Choose Modal (popup overlay) or Embedded (inline where the shortcode is). You can also override per shortcode:

`[didit_verify mode="embedded"]`

== Screenshots ==

1. Settings page — configure mode, credentials, display options, and button appearance.
2. Button Appearance section with live preview.
3. Verification button on a page.
4. WooCommerce checkout with verification step.

== Changelog ==

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

= 0.1.0 =
First release.
