=== Smart Login ===
Contributors: azarpixel
Tags: login, registration, email verification, security, woocommerce
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Modern, secure login & registration plugin with email verification (code + link), for standard WordPress and WooCommerce sites.

== Description ==

Smart Login provides a single-shortcode login and registration form, `[smart_login_form]`, with mandatory email verification for new accounts — supporting both a 6-digit code and a clickable link in the same email.

Highlights:

* Shortcode-only front end — no wp-login.php replacement, no forced WooCommerce override.
* Email verification via code and link, either of which completes verification.
* Configurable policy for whether unverified users can log in.
* Honeypot and optional Google reCAPTCHA v3 / Cloudflare Turnstile bot protection.
* Login lockout after repeated failed attempts.
* Optional WooCommerce My Account login/register form replacement.
* Settings UI built on the AzarPixel WP Admin UI Kit.

= Requirements =

* WordPress 6.0+
* PHP 8.0+
* WooCommerce is optional and soft-detected.

== Installation ==

1. Upload the `smart-login` folder to `/wp-content/plugins/`.
2. Activate the plugin through the "Plugins" screen.
3. Configure the plugin under the "Smart Login" admin menu.
4. Place `[smart_login_form]` on any page or post.

== Changelog ==

= 1.3.0 =
* Added a "Send test email" diagnostic under Settings → Emails, and always-on logging of any failed `wp_mail()` call site-wide (not just this plugin's own sends) — use this to tell a Smart Login bug apart from a broken SMTP setup (e.g. WP Mail SMTP misconfiguration).
* Added an "Allowed countries for registration" setting (Settings → General): admin-selectable per-country checklist controlling which countries appear in the mobile-number country selector, enforced server-side on registration too. United States, Canada, and Western Europe are enabled by default.
* The mobile-number country selector now shows a real SVG flag next to the dial code (previously an emoji, which doesn't render as an actual flag on every OS).
* On narrow/mobile screens, the country selector and the phone number now stack onto separate lines instead of being squeezed onto one, and the number field has a single clean placeholder.
* Fixed a CSS specificity bug where the phone-number field's country selector and number input could render stacked instead of side by side, and could render taller than every other field.

= 1.2.0 =
* Redesigned every text field as a Material Design floating label: the label sits inside the field as a placeholder at rest, then floats to a small caption above the field on focus or once filled.
* Made the whole form noticeably more compact — smaller fields, tighter spacing, smaller headings.
* Hardened the field/label CSS with high-specificity, `!important`-backed rules so the plugin's styles reliably win against themes that style `input`/`select`/`label` broadly.

= 1.1.2 =
* Fixed WooCommerce (or a theme) sending its own "Welcome to {site}" new-account email immediately on registration, ahead of and independent from this plugin's own verification-gated welcome email. Registration now suppresses any mail fired during account creation itself; the plugin's own verification email still sends immediately after, and its welcome email still sends only once the code/link is confirmed.

= 1.1.1 =
* Fixed "Could not save — please try again." on the settings page: the REST route the Save button posts to was only ever registered while viewing wp-admin, but the save request itself is a separate REST API request (not an admin request), so the route was never actually available and every save failed.

= 1.1.0 =
* Redesigned the registration form: First/Last name, Email, Mobile number (with country dial-code selector), Password — username is now auto-generated instead of collected.
* Redesigned the front-end card: square-cornered, boxed labels-inside-fields layout, two-line headings, "Forgot password?" link (via WordPress's native lost-password screen), and a hover-lift animation on all buttons.
* Redesigned the verify step: one input box per digit (auto-advance, backspace, full-code paste), and its intro text is now editable from Settings → Verification.
* Added `?action=register` URL support so the registration form is directly linkable/bookmarkable, not only reachable via the in-page switch link.
* Changed the default resend cooldown from 60 to 120 seconds (still configurable).
* Stored the mobile number as user meta (`sml_phone`).

= 1.0.0 =
* Initial release: settings framework, verification core (code + link), registration and login handlers with lockout, honeypot + reCAPTCHA v3/Turnstile bot protection, email templating, shortcode front end, and optional WooCommerce My Account integration.
