=== Smart Login ===
Contributors: azarpixel
Tags: login, registration, email verification, security, woocommerce
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.1.0
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

= 1.1.0 =
* Redesigned the registration form: First/Last name, Email, Mobile number (with country dial-code selector), Password — username is now auto-generated instead of collected.
* Redesigned the front-end card: square-cornered, boxed labels-inside-fields layout, two-line headings, "Forgot password?" link (via WordPress's native lost-password screen), and a hover-lift animation on all buttons.
* Redesigned the verify step: one input box per digit (auto-advance, backspace, full-code paste), and its intro text is now editable from Settings → Verification.
* Added `?action=register` URL support so the registration form is directly linkable/bookmarkable, not only reachable via the in-page switch link.
* Changed the default resend cooldown from 60 to 120 seconds (still configurable).
* Stored the mobile number as user meta (`sml_phone`).

= 1.0.0 =
* Initial release: settings framework, verification core (code + link), registration and login handlers with lockout, honeypot + reCAPTCHA v3/Turnstile bot protection, email templating, shortcode front end, and optional WooCommerce My Account integration.
