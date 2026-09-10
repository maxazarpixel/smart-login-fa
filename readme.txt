=== Smart Login ===
Contributors: azarpixel
Tags: login, registration, email verification, security, woocommerce
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.9.1
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

= 1.7.1 =
* A login attempt with correct credentials but an unverified email now drops the user straight onto the verify-code screen (with a fresh code just sent) instead of showing an error and leaving them on the login form. Removed the now-unused inline "Resend verification email" prompt this replaces.

= 1.7.0 =
* Added a fully branded Forgot Password / Reset Password flow, replacing the previous redirect to wp-login.php: "Forgot password?" now opens an in-form panel to request a reset link, and the emailed link opens a matching "Set a New Password" panel — same card, same styling as the rest of the form. Built on WordPress's own reset-key primitives (`get_password_reset_key()`, `check_password_reset_key()`, `reset_password()`), not custom crypto.
* The reset email is admin-editable under Settings → Emails → "Password reset email", using the same {user}/{site_name}/{link} placeholders and branded button rendering as the other emails.
* Successfully resetting a password via the emailed link now also marks the account's email as verified, if it wasn't already — using a single-use link mailed to the account's own address is the same proof of ownership the code/link verification flow exists to establish.

= 1.6.1 =
* Increased spacing between the floated label and the field value once a field is focused or filled — they were rendering only ~2px apart.

= 1.9.1 =
* The "Allowed countries" and "Roles allowed to log in" admin fields are now a searchable multi-select dropdown (type to filter, click to check/uncheck, selected items shown as removable chips) instead of a plain ctrl/cmd-click list box — much easier to use with ~195 countries.

= 1.9.0 =
* Added a "Roles allowed to log in" setting (Settings → Security → Login access): a multi-select of WordPress/WooCommerce user roles permitted to log in through the [smart_login_form] form. Leave empty (default) to allow every role. A user whose role isn't selected — e.g. blocking Administrators while only allowing Customer — is signed back out immediately with a clear on-screen message. This only affects the Smart Login form, not wp-login.php.

= 1.8.0 =
* Added optional login requirement for Cart and Checkout (Settings → WooCommerce): a visitor who isn't logged in is redirected to a configured login page and sent back to the exact page they wanted (Cart or Checkout) once they log in or verify their email — including through the verify-link-in-email path, not just the in-page code entry. Enable/disable independently per page; if no login page is configured, this protection does nothing rather than risk locking visitors out.
* Added disposable/temporary email blocking (Settings → General): registration is rejected with a clear message when the email's domain matches an admin-editable list of disposable domains, seeded with a starter list of common throwaway-email providers. Toggle on/off independently.
* The "Allowed countries for registration" setting is now a true multi-select list box (previously a checkbox grid) and now covers essentially every country, not just ~27. Default-enabled countries (United States, Canada, Western Europe) are unchanged.
* The mobile-number country selector's flag now displays for every country: hand-drawn SVG flags for the original ~27 countries, and a neutral ISO-code badge (rather than a guessed/inaccurate flag design) for the rest.
* Added mobile number validation and live formatting based on the selected country: the maximum digit length is derived from the country's dial code (per ITU-T E.164), and the number is grouped into readable digit clusters as it's typed.
* Confirmed front-end and admin CSS/JS continue to load only on the pages that actually use them — the shortcode's assets only enqueue when `[smart_login_form]` renders, and the admin UI kit's assets only enqueue on the Smart Login settings screen — no site-wide asset loading.

= 1.6.0 =
* Added a Dashboard tab (Settings → Dashboard, now the default landing tab): All Users and Verified Email KPI cards, a sign-ups-per-month chart for the last 6 months, and a recent-logins table (name, email, verified status, last login).
* Pre-existing accounts (created before this plugin was active) are never blocked from logging in for being unverified — only accounts actually registered through Smart Login are subject to the "Block login until verified" policy. A pre-existing unverified user instead gets a verification email automatically after a successful login (at most once per day), with an on-screen notice telling them so.
* Login now records a last-login timestamp for every user (any login path, not just this plugin's form), powering the new dashboard table.

= 1.5.0 =
* Added "Button background color" and "Button text color" settings (Settings → General) — controls the primary button (Log In, Create Account, Verify) across the whole form; secondary buttons and links are unaffected.

= 1.4.0 =
* Redesigned every email (verification, resend, welcome) as a proper branded HTML message — a card with the site name in the header, the verification code shown as a large letter-spaced badge, the verify link shown as a real button (with the plain URL underneath as a fallback), and an "automated message" footer — instead of plain text with raw placeholders.
* Subject/body settings are unchanged (still plain text with {code}/{link}/{user}/{site_name}/{expiry_minutes} placeholders, still editable under Settings → Emails) — only the rendering improved, so existing customizations keep working.

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
