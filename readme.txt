=== Didit Verify ===
Contributors: alexdidit
Tags: kyc, identity verification, age verification, woocommerce, aml
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.4.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Identity verification (KYC), age verification and AML screening for WordPress and WooCommerce, run by your Didit workflows.

== Description ==

Didit Verify adds identity verification to WordPress and WooCommerce. You build the checks in the Didit Console as a workflow, and the plugin runs that workflow wherever you need it: at registration, at checkout, on age-restricted products, in My Account, or for business customers.

**Workflows, not one check per plugin**

A Didit workflow can combine an ID document check, liveness, face match, AML (anti-money laundering) screening with ongoing monitoring, age estimation, phone and email verification, proof of address (PoA), database validation and, for companies, KYB (know your business) registry and key people checks. The plugin lists your workflows with the checks each one runs, and you choose one per placement:

* **Registration** - new accounts verify before they are created (WordPress and WooCommerce registration forms).
* **WooCommerce checkout** - classic and block checkout, before the order is placed or after purchase.
* **WooCommerce age gate** - age-restricted products and whole categories cannot be added to the cart until the shopper is approved by the age workflow.
* **My Account re-verification** - a Verification tab where customers check their status and verify again.
* **Business customers** - a KYB workflow from the same tab, tracked apart from the person's own identity check.

An approval only counts for the workflow that produced it, so an approval from a light check never opens a gate that needs a stricter one.

**Every result where you work**

WooCommerce orders and user profiles show each check's result from the Didit decision: ID document (type, subtype and country), liveness and face match scores, age, AML hits with PEP and sanctions flags and the ongoing-monitoring status, phone, email, proof of address, database validation and business registry data. A link opens the verification in the Didit Console in a new tab. Reviewer decisions and ongoing-monitoring updates reach the site by webhook later, without any action from you.

**Optional direct checks**

* **AML screening at checkout** - screen the billing name against sanctions and PEP lists when an order reaches an amount you choose, with optional ongoing monitoring and order hold.
* **Phone and email verification** - ask customers to confirm the phone and email on their order with a one-time code.

**Sign in with Didit**

Add a "Sign in with Didit" button to the WordPress login page, with the sign-in methods your Didit application allows, including passkeys. Accounts link by verified email; accounts that can manage the site link only from their own profile.

**Secure by design**

* Only a decision confirmed with Didit on your server grants access. Browser claims never do.
* The browser names a placement, never a workflow, and your API key never leaves the server.
* Webhooks are signature-checked, replays change nothing, and results are read from Didit rather than from the delivery.
* The plugin keeps a short summary of each result. It never stores ID images, selfies or raw verification data.

**Also included**

* `[didit_verify]`, `[didit_gate]` and `[didit_status]` shortcodes, with modal, embedded and redirect display modes.
* Button appearance settings with a live preview.
* Translatable, with German translations included.
* Hooks for developers: `didit_session_created`, `didit_verification_completed`, `didit_verification_cancelled` and the `didit_sdk_url` filter, plus the `didit:complete` browser event.

== Third-Party Service ==

This plugin connects to the [Didit](https://didit.me) identity verification service to process user verifications. When a verification session is created (API mode), when the admin page lists your workflows, when an order is screened, when a phone or email code is sent, and when someone uses Sign in with Didit, the plugin sends data to Didit's servers (`verification.didit.me`, and your application's sign-in address for Sign in with Didit). When the verification UI is displayed, an iframe loads content from `verify.didit.me`.

This plugin bundles the [Didit Web SDK](https://www.npmjs.com/package/@didit-protocol/sdk-web) (version 0.2.1) as `assets/js/didit-sdk.umd.min.js`. The full unminified source code is publicly available at the GitHub repository and npm package linked below.

* Service: [https://didit.me](https://didit.me)
* SDK source code: [https://github.com/didit-protocol/sdk-web](https://github.com/didit-protocol/sdk-web)
* SDK npm package: [https://www.npmjs.com/package/@didit-protocol/sdk-web](https://www.npmjs.com/package/@didit-protocol/sdk-web)
* SDK license: MIT
* Terms of Use: [https://didit.me/en/terms/identity-verification/](https://didit.me/en/terms/identity-verification/)
* Privacy Policy: [https://didit.me/en/terms/privacy-policy/](https://didit.me/en/terms/privacy-policy/)

The SDK can be rebuilt from source with `npm install && npm run build` (uses Rollup). See the GitHub repository for full build instructions.

No data is sent to Didit until the site administrator configures the plugin. Identity verification starts only when a user starts it. Phone and email codes are sent only when the customer asks for one on the order page. If the administrator turns on automatic AML screening, placing an order at or above the set threshold sends the billing name and an order or customer reference to Didit in the background, without any action from the shopper.

== Installation ==

1. In WordPress, go to **Plugins > Add New**, search for **Didit Verify**, then click **Install Now** and **Activate**.
2. Create a free account at [business.didit.me](https://business.didit.me) and build a workflow with the checks you need.
3. In **Settings > Didit Verify**, choose **API Session** mode and paste your API key and default Workflow ID from the Didit Console.
4. Under **Workflows and placements**, choose a workflow for each place where you want verification, and switch on the placements you use.
5. Paste the webhook address shown under **Security** into the Didit Console (API & Webhooks) and copy the webhook secret back, so reviewer decisions and monitoring updates reach your site.

You can test everything with a sandbox application first: sandbox checks are free.

== Frequently Asked Questions ==

= Which checks can I use? =

Any check you add to a workflow in the Didit Console: ID document, liveness, face match, AML screening and ongoing monitoring, age estimation, phone, email, proof of address, database validation, and KYB registry and key people checks. The plugin shows the result of each one, so new checks you add to a workflow appear without updating the plugin.

= Can I use different checks in different places? =

Yes. Each placement (registration, checkout, age-restricted products, My Account, business customers) can run its own workflow. Placements without a choice use the default Workflow ID.

= How does the WooCommerce age gate work? =

Mark products as **Age-restricted** in Product data > Advanced, or choose whole categories under **Workflows and placements**. Shoppers see a "Verify my age" button on those products, and the products cannot be added to the cart or bought until the age workflow approves the shopper.

= How does AML screening at checkout work? =

When it is on and an order reaches the amount you set, the plugin screens the billing name in the background and shows the result and any hits on the order. You can keep screened customers under ongoing monitoring and put orders that need review on hold. Admins can run the screening again from the order actions.

= Can business customers verify their company? =

Yes. Choose a KYB workflow for **Business customers** and switch the placement on. Customers verify their company from My Account > Verification, and the result appears on their user profile and in a KYB column in Users.

= How does Sign in with Didit work? =

Turn on Authentication for your application in the Didit Console, register the redirect URL shown in the plugin settings, and paste your publishable key and application ID. People sign in on Didit's sign-in page with the methods you allow, including passkeys. An account links by its verified email; accounts that can manage the site link only from their own profile, and new accounts are only created when anyone can register.

= What happens to personal data when I delete it? =

The WordPress personal data eraser (Tools > Erase Personal Data) deletes the person's Didit results, status and sign-in link from your site. Uninstalling the plugin deletes all of its settings and every Didit result stored on users and orders. Verification data held by Didit is deleted from the Didit Console.

= Can I try it without real customers? =

Yes. Use a sandbox application in the Didit Console. Sandbox checks are free, and you can set the outcome of a sandbox session to test approvals, declines and reviews.

= Is my API key safe? =

Yes. The key is stored in your WordPress database and used only in server-to-server calls. It is never included in any page or script sent to the browser.

= What is the difference between UniLink and API mode? =

UniLink opens one shared verification link and does not grant access to protected content or checkout. API mode creates a session per visitor, confirms every decision with Didit, and is required for every placement and result described here.

= How do I restrict content to verified users? =

Wrap content in `[didit_gate]...[/didit_gate]`. Visitors who are not verified see a message and a verification button. `[didit_status]` shows the visitor's status anywhere.

= How do I check if a user is verified in PHP? =

`Didit_Verify::init()->is_user_verified( $user_id )` returns `true` when Didit approved the user. `Didit_Verify::init()->user_has_workflow_approval( $user_id, $workflow_id )` checks a specific workflow.

= When should I use Redirect display? =

When the camera cannot start inside a frame, for example the liveness step on some Android Chrome devices. Redirect opens Didit's hosted page in the browser; shoppers return to the page where they started.

== Screenshots ==

1. Your Didit workflows with the checks each one runs, and the workflow chosen for each placement.
2. A WooCommerce order with every check's result, AML hits and ongoing monitoring, and a link to the Didit Console.
3. Verification at WooCommerce checkout.
4. An age-restricted product asking the shopper to verify their age.
5. The Verification tab in My Account, for people and businesses.
6. Sign in with Didit on the WordPress login page.

== Changelog ==

= 0.4.0 =
* New: the settings page lists your Didit workflows and the checks each one runs, and you choose a workflow per placement: registration, checkout, age-restricted products and categories, My Account re-verification and business customers (KYB).
* New: an approval only opens gates of the workflow that produced it. Earlier approvals keep counting for the default workflow.
* New: WooCommerce orders and user profiles show every check's result (ID document, liveness, face match, age, AML hits and ongoing monitoring, phone, email, proof of address, database validation, business registry and key people), with a link to the Didit Console.
* New: reviewer edits and ongoing-monitoring updates (`data.updated` webhooks) refresh results, read again from Didit. The `Resubmitted` and `Awaiting User` statuses are recognized.
* New: optional AML screening of the billing name above an order amount, with ongoing monitoring and order hold, and an order action to run it again.
* New: optional phone and email verification of the order contact with one-time codes.
* New: optional Sign in with Didit on the WordPress login page.
* New: the WordPress personal data eraser removes a person's Didit results, and uninstalling removes every result stored on users and orders.
* Releases are published to WordPress.org automatically from GitHub.


= 0.3.5 =
* Fix: retrying verification no longer creates another session or spends the visitor's hourly limit. The plugin reopens the session this browser (or this WooCommerce order) is already in, so a shopper can keep retrying until the flow completes. Before reopening a session the plugin asks Didit for its current status, so approved, declined, abandoned and expired sessions start a new verification, and the creation limit still stops a runaway loop.
* New Redirect display mode: the verification runs on Didit's hosted page instead of inside a frame, the way out of camera or liveness failures inside an iframe on Android Chrome. The shopper returns to the page where they started; the plugin then confirms the result and keeps the checkout session.
* When a shopper returns from the hosted page, the plugin confirms the result on the server and marks the checkout as soon as the session is approved.
* The language setting now offers all 54 verification languages (adds Albanian, Bosnian, Kazakh, Kyrgyz and Mongolian).

= 0.3.4 =
* Security: revoke protected-content access when the approved verification is declined, expires, or returns to review, even if another verification has started.
* Keep a newer approved verification unaffected by updates to an older session.

= 0.3.3 =
* Add Didit branding and grouped cards to the WordPress settings page.
* Show readable verification status badges in Users and WooCommerce order details.
* Add direct links to user results, Didit Console, and the integration guide.

= 0.3.2 =
* Confirm verification decisions on the server before granting content or checkout access.
* Bind sessions to their originating visitor or order and reject unrelated session IDs.
* Require confirmed approval for both classic and block WooCommerce checkout.
* Load verification scripts when checkout blocks are embedded on a custom page.
* Show confirmation, review and retry states without trusting browser-reported decisions.
* Refresh default button styling and keyboard focus visibility.


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

= 0.4.0 =
Choose a Didit workflow per placement, see every check's result on orders and profiles, and add optional AML screening, phone and email codes and Sign in with Didit.


= 0.3.4 =
Security update for protected content: revocations now apply to the verification granting access even when another attempt is pending.

= 0.3.3 =
Branded administration and clearer verification results. Includes the server-confirmed approval protections introduced in 0.3.2.

= 0.3.2 =
Security update: use API Session mode for protected content and checkout. Existing users must verify again. Browser-reported approvals from earlier releases no longer grant access.


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
