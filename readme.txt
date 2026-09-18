=== Smart Login ===
Contributors: azarpixel
Tags: login, registration, email verification, security, woocommerce
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.25.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Modern, secure login & registration plugin with email verification (code + link), for standard WordPress and WooCommerce sites.

== Description ==

Smart Login provides a single-shortcode login and registration form, `[smart_login_form]`, with mandatory email verification for new accounts — supporting both a 6-digit code and a clickable link in the same email.

Highlights:

* Shortcode-only front end — no wp-login.php replacement, no forced WooCommerce override.
* Optional "Continue with Google" sign-in / sign-up, off by default.
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
* Google sign-in does not pass through WordPress's own login stack, so plugins that add two-factor authentication on the `authenticate` filter do not run on it. Administrator-capable accounts are therefore excluded from Google sign-in unless you turn on "Allow Google sign-in for site administrators", and the `sml_google_allow_login` filter lets a security plugin refuse any Google sign-in.
* The debug log lives at `wp-content/uploads/smart-login-logs/` under a per-site random filename, with `.htaccess` and `web.config` deny rules. It never contains verification codes or secrets, but it does record recipient addresses of plugin emails — if your host serves that directory, delete the folder after troubleshooting.

= Caching =

The page carrying `[smart_login_form]` is dynamic — the same URL renders differently per visitor (a `redirect_to` from Cart/Checkout, a password-reset or verification link, an OAuth round trip) and its result changes whether the visitor ends up logged in. On any request carrying that kind of state, Smart Login sets `DONOTCACHEPAGE` and no-cache headers, which every major WordPress caching plugin (WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, Breeze, Cache Enabler) and most managed-hosting caches (WP Engine, Kinsta, SiteGround) respect.

That signal only reaches a cache that WordPress itself is running behind. A CDN or reverse proxy in front of WordPress (Cloudflare's "Cache Everything", Varnish, an Nginx `fastcgi_cache` rule) can serve a cached response for a URL without ever reaching PHP, so it never sees these headers at all. If you use one of these:

* Exclude the page containing `[smart_login_form]` from full-page caching entirely, or
* Bypass cache whenever the request carries a `wordpress_logged_in_*` cookie (most caching plugins already do this; a raw CDN page rule usually needs it configured explicitly), and
* Bypass cache for any request whose query string contains `redirect_to`, `action`, `sml_verify`, `sml_reset`, `sml_google`, or `sml_google_error`.

After changing any caching configuration, purge the cache and test in a private/incognito window.

== Installation ==

1. Upload the `smart-login` folder to `/wp-content/plugins/`.
2. Activate the plugin through the "Plugins" screen.
3. Configure the plugin under the "Smart Login" admin menu.
4. Place `[smart_login_form]` on any page or post.

== Changelog ==

= 1.25.0 =
* Fixed: a customer sent to a "Pay for Order" link (e.g. `/checkout/order-pay/123/?pay_for_order=true&key=...`) for an order tied to an account, while logged out, landed on the site home after logging in instead of back on that payment page. WooCommerce renders the login form directly on that same order-pay page rather than redirecting to it, so there was never a `redirect_to` for the form to carry — it now falls back to the current page's own URL whenever it's shown this way (on Pay for Order or on My Account visited directly), for both the password and the Google sign-in path.

= 1.24.2 =
* Fixed: on a site with page caching, a visitor sent from Cart/Checkout to the login page (or through the "Continue with Google" round trip, or a password-reset/verification link) could see a stale response — a `redirect_to` destination baked into a cached page from an earlier, unrelated visit, or a page that doesn't yet reflect a sign-in that just happened. These requests now send `DONOTCACHEPAGE` and no-cache headers, which WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, Breeze, Cache Enabler and most host-level caches all honour.
* This does not cover a CDN or reverse proxy (e.g. Cloudflare, Varnish) caching by URL ahead of WordPress, without regard to cookies or query string — see "Caching" in this readme.

= 1.24.1 =
* Fixed: signing up with Google for the first time created the account but did not sign the visitor in — they had to click "Continue with Google" a second time. The welcome email (and WooCommerce's own new-account email, fired during account creation) were sent before the login cookie was issued, so a slow or failing SMTP hop could stall or end the request in between. The session is now established first and the email sent afterwards, and third-party mail is suppressed during account creation exactly as the password registration flow already does it.

= 1.24.0 =
* Security: the Google sign-in state token is now tied to the browser that started the flow, via a short-lived HttpOnly / SameSite=Lax cookie that must match the stored token. Previously the token was only held server-side, so an attacker could start a sign-in, hand their own link to someone else, and silently sign that person's browser into the attacker's account.
* Security: accounts that can administer the site (manage_options / edit_users) can no longer sign in with Google unless an admin explicitly turns on the new "Allow Google sign-in for site administrators" setting. Google sign-in does not run WordPress's normal login checks, so any two-factor plugin hooked there is skipped — this stops that shortcut reaching an administrator account by default.
* Security: added the `sml_google_allow_login` filter so security plugins can refuse a Google sign-in, restoring the veto they would normally get from the standard login flow.
* Security: linking Google to an existing account is now a setting ("Link Google to existing accounts", on by default), and the account owner is emailed the first time it happens — linking means that account can be signed into without its WordPress password, so it is no longer silent. The new message is editable under Settings → Emails → "Google linked email".
* Security: added PKCE (S256) to the Google OAuth flow, and made the `email_verified` check strict rather than merely truthy — that one field is what licenses matching a Google identity to an existing account by email.
* Security: the debug log now uses an unguessable per-site filename and ships a `web.config` alongside its `.htaccess`. The previous fixed `debug.log` name was protected only by `.htaccess`, which nginx and IIS ignore — leaving recipient email addresses and diagnostics downloadable on those servers. The log also rotates at 2 MB instead of growing without limit, and Google token errors now log only Google's error code, never the whole response body.
* Security: the "Resend verification email" endpoint no longer answers differently while an account is inside its cooldown. That difference let the endpoint be used to confirm which user IDs exist and are unverified, which the uniform response was meant to prevent; the countdown the caller sees is unchanged.
* Added a "Keep Google sign-ins signed in" setting — Google sign-in previously always issued a 14-day persistent session, with no way to shorten it for shared or public computers.

= 1.23.0 =
* Added "Continue with Google" sign-in / sign-up (Settings → Social Login), off by default. A self-contained OAuth 2.0 module (`SML_Google_Auth`) with its own on/off toggle, Client ID / Secret fields, a one-click JSON-upload that reads a downloaded Google Cloud OAuth client file locally in the browser and fills both fields, and a copyable "Redirect URI" to register in the Google Cloud Console. Matches an existing account by verified email (linking it), or creates a new one — both are marked verified immediately, since Google already proved the email. Respects "Roles allowed to log in" and the registration/disposable-email settings for new accounts.

= 1.22.0 =
* Each email under Settings → Emails now has a "Preview email" button that renders that message exactly as a customer receives it — the full branded HTML with header, card, styled code/button and footer — using sample data, and including whatever you have typed in the body field before saving.

= 1.21.0 =
* The Users table is now interactive: an AJAX (admin-ajax) search box with no page reload, filter dropdowns for verification status, WooCommerce customer (has / no orders) and registration window (24h / 7d / 30d / 12mo), click-to-sort columns (name, email, registered, last login, resets, orders, spend), a numbered pager, and a live results count. The server returns the rendered rows/pager, so all escaping and formatting stay in PHP.

= 1.20.0 =
* The "Users" tab is a real table again — this time rendered server-side (no REST/JS, which is what failed to load before). Columns: name, email, phone, WooCommerce billing address, registered, last login, verification status, password-reset count, and — when WooCommerce is active — order count, lifetime spend and last-order date. Search by name / email / address / phone with paging; both use plain links so they work without JavaScript.

= 1.19.0 =
* The Dashboard now has a "Most password-reset requests" table — the accounts that have asked for a reset link the most, with the request count and when they last asked. Useful for spotting a confused customer or abuse. Each forgot-password submission for a real account is counted from now on (older history isn't backfilled).

= 1.18.2 =
* Dashboard polish: the sign-up chart's solid-black bars are now a soft indigo gradient sitting in a light column track, with a baseline and no more clipped value label on the tallest bar. The KPI cards get a light background, a thin colour accent on top, and softer (not pure-black) numbers.

= 1.18.1 =
* A completed password reset now runs the full verification-complete path (clears any pending code/link row and fires the `sml_user_verified` hook), not just the meta flag — using a single-use link mailed to the account is proof of inbox ownership, same as the code/link flow. The account has been marked verified this way since 1.7.0; this only makes it consistent with the code path.

= 1.18.0 =
* User management moved to the native WordPress Users screen. It now shows "Verified" (Verified / Pending / Legacy), "Phone", "Last login" and "Registered" columns, with sortable Last login / Registered and Verified / Pending / Legacy filter links above the list. The plugin's own "Users" tab is now a shortcut to that screen; its bespoke REST-backed table (which wasn't loading on some sites) was removed.

= 1.17.2 =
* Added a "Settings" link to the plugin's row on Plugins → Installed Plugins.

= 1.17.1 =
* "Pre-existing unverified accounts" (Settings → Verification) gained a third choice, "No verification needed — log them straight in", which is now the default: accounts that predate the plugin are left completely alone and only new registrations are ever asked to verify. The "reminder email" and "skippable verification screen" options are still available.

= 1.17.0 =
* Expanded the Dashboard tab: verification breakdown cards (Verified / Pending / Legacy with percentages), a "New sign-ups" panel with 24-hour / 7-day / 30-day counts, a "Logins (24h)" figure, a total on the 6-month sign-up chart, and side-by-side "Recent sign-ups" and "Recent logins" tables that now also show phone and a three-state status.

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
