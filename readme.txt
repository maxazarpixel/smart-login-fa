=== Smart Login ===
Contributors: azarpixel
Tags: login, registration, email verification, security, woocommerce
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.16.0
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

= Security notes =

* Registration enforces an 8-character minimum password length, matching the password-reset flow.
* Login lockout and the resend / reset rate limits are keyed on `REMOTE_ADDR`. Behind a reverse proxy or CDN, configure your server so `REMOTE_ADDR` is the real client IP (e.g. Apache `mod_remoteip`, nginx `real_ip`, or your platform's trusted-proxy setting). Smart Login never reads `X-Forwarded-For` directly, because a client can spoof it.
* The honeypot time-trap filters only unsophisticated bots. For a public site, also enable a bot-protection provider (reCAPTCHA v3 or Turnstile) under Settings → Security.
* Distributed, IP-rotating credential stuffing against a single account is best mitigated at the edge (WAF, Cloudflare, fail2ban). The built-in lockout is per (IP, username) by design, so a targeted lock-out-the-victim attack is not possible through it.

== Installation ==

1. Upload the `smart-login` folder to `/wp-content/plugins/`.
2. Activate the plugin through the "Plugins" screen.
3. Configure the plugin under the "Smart Login" admin menu.
4. Place `[smart_login_form]` on any page or post.

== Changelog ==

= 1.16.0 =
* Added a "Users" tab (Settings → Users). Paged, searchable list of every account showing name, email, phone, WooCommerce billing address, sign-up date, last login, and verification status (Verified / Pending / Legacy). Search matches name, email, address and phone.

= 1.15.0 =
* Added a "Pre-existing unverified accounts" option (Settings → Verification → "Access before verification"). "Show a verification screen they can skip" drops these users onto the code-entry screen after login, with a "Not now" button that lets them continue to their destination (cart, checkout, account). Default stays "Send a reminder email only". These accounts are still never blocked.
* Fixed: a pre-existing unverified account was tagged with the verification meta on its first login, which then made a later login treat it as a real Smart Login registration and apply the "block until verified" policy. It now stays classified as pre-existing until it actually verifies.

= 1.14.1 =
* Fixed: on WooCommerce sites the password-reset link (pointed at /my-account/) was hijacked by WooCommerce's own lost-password handling — it grabbed the `key` / `login` query args, set a cookie and bounced to /my-account/lost-password/?show-reset-form=true with no form shown. The reset link now uses `sml_key` / `sml_login`, which WooCommerce ignores, so the Smart Login "Set a New Password" panel opens directly. Older links (bare `key` / `login`) still work on non-WooCommerce pages.

= 1.14.0 =
* Added "Import / export settings" under Settings → Advanced. Export downloads every setting as a JSON file (bot-protection secret keys are left out, so the file is safe to share for review); Import accepts a pasted or uploaded JSON, applies only recognised keys, and keeps any secret key already stored.

= 1.13.3 =
* Links in the plugin's emails (the verify / reset button, the plain-text URL fallback, and any links in the admin-authored body or footer text) now open in a new tab.

= 1.13.2 =
* The front-end form no longer follows the operating system's dark-mode setting — it now always renders on a light (white) card. Removed the dark colour scheme; `color-scheme: light` also keeps the country dropdown, autofill hints and scrollbars light on dark-mode devices.

= 1.13.1 =
* Fixed: reset / verification links still landed on the home page on sites that use the WooCommerce "Replace My Account login form" option without a separate shortcode page. When that option is on, links now point at the My Account page, which renders the Smart Login form for logged-out visitors.

= 1.13.0 =
* Fixed: the password-reset link opened the site home page instead of the reset form when the shortcode wasn't on the home page. Reset and email-verification links now point at the page that actually holds `[smart_login_form]`.
* The "Login page" setting moved from Settings → WooCommerce to Settings → General → "Shortcode & form page", since it now also drives where reset / verification links land — not just the Cart/Checkout gate. The saved value carries over.
* If no Login page is set, the plugin now auto-detects the page containing the shortcode (cached for a day; refreshed when settings or a page are saved) and falls back to the home page only if it can't find one.

= 1.12.1 =
* When Cloudflare Turnstile is the selected provider, each form's submit button is now disabled until the Turnstile challenge has passed — an account, login, or password request can't be sent before verification. The button re-locks if the token expires or a submit fails, and unlocks again once a fresh token is issued.

= 1.12.0 =
* Bot protection now covers every form. The selected provider (Google reCAPTCHA v3 / Cloudflare Turnstile) is verified on the login, registration, forgot-password and reset-password submissions — previously only registration was checked.
* The Cloudflare Turnstile widget now renders just above each form's submit button instead of at the very top of the card.
* Fixed: "Bot verification failed. Please try again." after a first submit that failed for another reason (e.g. a rejected email). Turnstile tokens are single-use; the widget is now reset after any failed submit so the retry gets a fresh token, and the token is read live from the widget rather than a cached copy.
* Turnstile is now rendered explicitly, one widget per visible panel, so switching tabs no longer leaves stale or duplicate challenges on the page.

= 1.11.2 =
* Theme compatibility: the verification-code digit boxes now hold their own square shape, background and font size instead of picking up a host theme's generic `input[type=text]` styling (which was rendering them as tall grey pills on some themes).

= 1.11.1 =
* Theme compatibility: every visible form field now carries an `.sml-input` class, and the stylesheet re-asserts the plugin's own field colours/shape for the properties host themes commonly override with generic `input[type=...]` / `.wd select` rules.
* Fixed: Chrome autofill made fields adopt the host theme's colours (via its `input:-webkit-autofill` rules). Autofilled fields now use the plugin's own background and text colour, matching a typed value.
* Fixed: the button in every plugin email was always near-black. It now uses the configured button colours (Settings → General → "Button appearance"), matching the front-end form.

= 1.11.0 =
* Fixed: the password-reset email's button read "Verify Email" — it now reads "Reset Password". The button label is set per email type rather than hard-coded.
* Fixed: after logging in, verifying an email, or resetting a password with no specific destination requested, users were always dropped on the site home page. Added a "Redirect after login" page setting (Settings → General) — point it at your My Account page, for example. Requests that already carry a destination (Cart/Checkout gating, etc.) are unchanged.
* Added: "Branding & footer" section under Settings → Emails — an overridable site name shown in the email header/footer (blank = WordPress site title) and an optional free-text footer block for company name, address, or support contact. {site_name} is available in the footer text.

= 1.10.0 =
* Security: registration now enforces the same 8-character minimum password length that the reset flow already required — the form gates Cart and Checkout, so a one-character password there was a real account-takeover path.
* Security: the bot-protection secret key is no longer returned in plaintext by the settings REST endpoint. The read path now returns the same "unchanged" placeholder the save path already used, so an untouched round-trip still leaves the stored value alone.
* Security: added IP-scoped rate limiting on top of the existing per-account cooldowns — 5 verification-email resends per 10 minutes, and 5 password-reset-link requests per 15 minutes, per client IP. Blunts email-bombing and reset-link volume abuse aimed at arbitrary accounts.
* Security: the "Resend verification email" endpoint is now enumeration-safe. A missing account, an already-verified account, and a genuine resend all return the same response; only a real, still-unverified account triggers another email.
* Security: "Roles allowed to log in" is now enforced on the email-verification and password-reset sign-in paths too, not only the login form. The verification or password change still completes; the session is just not established for a disallowed role.
* Security: the "Default role for new users" setting no longer lists roles that can administer the site (manage_options / edit_users), and registration falls back to Subscriber if a privileged role is somehow still stored — closing a self-registration privilege-escalation footgun.
* Docs: clarified that the honeypot time-trap is a minor deterrent only (recommend a bot-protection provider for public sites), and that lockout / rate limiting depend on REMOTE_ADDR being the real client IP behind a proxy or CDN. X-Forwarded-For is deliberately not trusted.
* Note: distributed (IP-rotating) credential stuffing against a single username is still best handled at the edge (WAF / Cloudflare / fail2ban); an IP-independent per-username lockout was considered but not added, since it would let an attacker lock a known user out on purpose.

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
