# Petit Form

Simple, secure lead-capture forms for WordPress. Shortcode-driven, leads stored in your own database, no bloat, no upsells, no tracking, no external service required.

Built for sites that need a contact or capture form that **just works and keeps working**: no license server, no feature gating, no vendor updates changing the markup under you.

## Is Petit Form for you?

**Yes, if:**
- you need a simple contact or lead-capture form (name, email, phone, message, consent checkbox)
- you want every lead stored in your own database — not just in an email
- you are tired of vendor upsells, dashboard nags and update anxiety
- you want a plugin small enough to be read, audited and maintained in one sitting — by you or by a coding agent

**No, if:**
- you need a drag-and-drop form builder, conditional logic, multi-step forms or payments → use WPForms, Fluent Forms or Gravity Forms
- you need dozens of pre-built integrations → vendor plugins exist for that
- pasting a shortcode into a page feels too technical → you want a visual builder, this is not it

## Built in the coding-agent era

Petit Form was created with coding agents, and is designed to be **maintained and audited by coding agents**: small enough to fit in one context window, stable error codes (`PF-Exxxx`) that make log lines diagnosable years later, smoke tests that run without WordPress (`php tests/smoke.php`), and only long-stable WordPress APIs. If an agent (or a human) needs to understand, patch or extend this plugin in five years, everything they need is in this repo.

## Why not WPForms / Contact Form 7 / Fluent Forms?

- WPForms Lite **does not store submissions** in the free tier — your leads only exist in an email.
- Vendor plugins ship dashboard widgets, upsell notices, their own CSS/JS and tracking.
- Petit Form is ~1 300 lines of PHP you can read in one sitting. Every lead is a row in your database. That is the whole product.

## Features

- Shortcode forms with declarative fields: text, name, email, tel, textarea, checkbox (GDPR consent)
- **Leads stored in your database** (`wp_petitform_leads`) with an admin list, per-form filter and CSV export
- Notification email with Reply-To set to the visitor, via `wp_mail()` — Petit Form has **no mail mechanism of its own**: it uses your site's existing mail stack (host sendmail by default, or any SMTP plugin you install), and the database remains the source of truth if email fails
- Generic outbound **webhook** (JSON POST, optional auth header) for CRMs and automation
- Developer action hook `petit_form_lead_created` for site-specific integrations
- Security: nonce, honeypot, signed time-trap, per-IP/per-form rate limiting, per-type sanitization, prepared statements, escaped output, capability checks on every admin action
- Optional **Cloudflare Turnstile** second layer (off by default)
- No file uploads, by design (that whole vulnerability class does not exist here)
- GDPR-aware: raw IPs are never stored (HMAC hash only), leads can be deleted one by one, optional data deletion on uninstall

## Install

1. Download the [latest release zip](https://github.com/emirbelkahia/petit-form/releases) (or clone this repo).
2. WordPress admin → Plugins → Add New → Upload Plugin → choose the zip → Activate.
3. A **Leads** menu appears in wp-admin. Settings live under Leads → Settings.

## Usage

```
[petit-form id="contact" fields="name:required, email:required, telephone, message:textarea:required, rgpd:checkbox:required:I accept the privacy policy"]
```

Field syntax: `key[:type][:required][:Custom label]`, comma-separated.
Types: `text`, `name`, `email`, `tel`, `textarea`, `checkbox`. When omitted, the type is guessed from the key (`email` → email, `message` → textarea, `rgpd`/`gdpr`/`consent` → checkbox, …).

Useful attributes: `submit="Button label"`, `success="Thank-you message"`.

## Security model

| Layer | Always on? | What it stops |
|---|---|---|
| WordPress nonce | Yes | CSRF — forged requests from another site |
| Honeypot field | Yes | Dumb bots that fill every field (~85 % of bot spam) |
| Signed time-trap | Yes | Bots submitting in under 3 s; the signature binds timestamp + form id + **field spec**, so visitors cannot tamper with `required` flags or field types. Valid 24 h |
| Rate limiting | Yes | Floods: 10 validated submissions/hour per IP per form (configurable; only validated submissions count, so typos don't burn quota) |
| Turnstile | Optional | Persistent/targeted bots. Enable in Settings with Cloudflare keys |
| Sanitization + validation | Yes | XSS payloads, malformed emails/phones, over-long values |
| Prepared statements | Yes | SQL injection |
| Escaped output | Yes | Stored XSS in the admin leads list |
| Capability checks + nonces on admin actions | Yes | The classic "CSRF on bulk delete" CVE pattern |

Error handling: every rejection carries a stable code (`PF-Exxxx`), shown to the visitor next to a friendly message and written to the PHP error log with technical context (never field values — safe to log in production). Set `define( 'PETIT_FORM_LOG', false );` in `wp-config.php` to silence logging.

| Code | Meaning |
|---|---|
| PF-E1001 | Shortcode misconfigured (missing id/fields) |
| PF-E1101 | Required field empty |
| PF-E1102 | Invalid email |
| PF-E1103 | Invalid phone |
| PF-E1104 | Field too long / payload over 60 KB |
| PF-E2001 | Nonce missing/invalid |
| PF-E2002 | Honeypot filled (bot) |
| PF-E2003 | Submitted too fast (bot) |
| PF-E2004 | Time-trap token forged, tampered field spec, or expired |
| PF-E2005 | Rate limit exceeded |
| PF-E2006 | Turnstile token missing |
| PF-E2007 | Turnstile rejected the visitor (or a 4xx from the API) |
| PF-E2008 | Turnstile API unreachable/5xx — submission accepted (fail-open, logged) |
| PF-E3001 | Database insert failed |
| PF-E3002 | Leads table creation failed (check DB user rights) |
| PF-E4001 | Notification email failed |
| PF-E4101 | Webhook delivery failed |

Design decision: when the Turnstile API is unreachable, submissions are **accepted and logged** (fail-open). Losing a real lead costs more than a spam wave during a Cloudflare outage. The database remains the source of truth.

## Customization

**Texts.** Per form, via shortcode attributes: `submit="..."`, `success="..."`, and field labels (`name:required:Your name`). Visitor-facing error messages can be overridden with one filter:

```php
add_filter( 'petit_form_user_message', function ( $message, $code ) {
    return 'PF-E2005' === $code ? 'Easy now — try again in a bit.' : $message;
}, 10, 2 );
```

**Styling.** There is deliberately no CSS editor in this plugin — WordPress already ships one: *Appearance → Customize → Additional CSS*. It is free, stored in the database, and survives updates. The plugin's stylesheet uses low-specificity, `.pf-`-prefixed selectors and no `!important`, so any rule you add there wins.

## For developers

```php
// Fires after a lead is stored. $values are sanitized, keyed by field key.
add_action( 'petit_form_lead_created', function ( $lead_id, $form_id, $values ) {
    if ( 'guide' === $form_id ) {
        // sync to your CRM, etc.
    }
}, 10, 3 );
```

## Tests

Pure logic (field parsing, sanitization, validation, honeypot, time-trap, rate limiting) is testable without WordPress:

```
php tests/smoke.php
```

Exit code 0 = all green. Run it before shipping any change.

## Known limitations, by design

- **Page caches**: a WordPress nonce lives 12–24 h. A form page served from a page cache older than that rejects submissions until the cache refreshes — set a cache timeout under 12 h on pages with a form (e.g. WP Fastest Cache → Cache Timeout).
- **Reverse proxies**: the client IP is read from `REMOTE_ADDR` only (`X-Forwarded-For` is forgeable). Behind a known proxy, map the real IP with the `petit_form_client_ip` filter, otherwise all visitors share one rate-limit bucket.
- **Submissions go through `admin-post.php`**: hardening setups that block `/wp-admin/` for anonymous users will block the form too.
- **Single site** (no multisite support), no entry repopulation after a server-side validation error, double-click = two leads (no JS dedup, by design).
- **Leads are kept forever** unless deleted manually (GDPR retention setting is on the roadmap).

## Roadmap

- v0.3: optional Akismet content check, per-form notification recipient, lead retention setting, WordPress privacy exporters/erasers for GDPR requests
- Not planned: drag-and-drop builder, conditional logic, payments, multi-step. If you need those, use a vendor plugin — that is their job, not this one's.

## Maintenance and contributing

Petit Form is a personal-use project shared in the open. Maintenance is **slow and best-effort**: I fix what I use, when I use it. Issues and pull requests are read and welcome — just don't expect SLA-grade response times. Security reports: please open a private security advisory on GitHub rather than a public issue.

## License

MIT — see [LICENSE](LICENSE).
