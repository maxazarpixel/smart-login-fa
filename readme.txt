=== Smart Login ===
Contributors: azarpixel
Tags: login, registration, email verification, security, woocommerce
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.0.0
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

= 1.0.0 =
* Initial release: settings framework, verification core (code + link), registration and login handlers with lockout, honeypot + reCAPTCHA v3/Turnstile bot protection, email templating, shortcode front end, and optional WooCommerce My Account integration.
