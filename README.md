# Petit Form

Simple, secure lead-capture forms for WordPress. Shortcode-driven, leads stored in your own database, no bloat, no upsells, no tracking, no external service required.

Built for sites that need a contact or capture form that **just works and keeps working**: no license server, no feature gating, no vendor updates changing the markup under you.

## Why not WPForms / Contact Form 7 / Fluent Forms?

- WPForms Lite **does not store submissions** in the free tier — your leads only exist in an email.
- Vendor plugins ship dashboard widgets, upsell notices, their own CSS/JS and tracking.
- Petit Form is ~1 000 lines you can read in one sitting. Every lead is a row in your database. That is the whole product.

## Features

- Shortcode forms with declarative fields: text, name, email, tel, textarea, checkbox (GDPR consent)
- **Leads stored in your database** (`wp_petitform_leads`) with an admin list, per-form filter and CSV export
- Notification email (best-effort; the database is the source of truth)
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
| Signed time-trap | Yes | Bots submitting in under 3 s, replayed/forged form tokens |
| Rate limiting | Yes | Floods: 5 submissions/hour per IP per form (configurable) |
| Turnstile | Optional | Persistent/targeted bots. Enable in Settings with Cloudflare keys |
| Sanitization + validation | Yes | XSS payloads, malformed emails/phones |
| Prepared statements | Yes | SQL injection |
| Escaped output | Yes | Stored XSS in the admin leads list |
| Capability checks + nonces on admin actions | Yes | The classic "CSRF on bulk delete" CVE pattern |

Error handling: every rejection carries a stable code (`PF-Exxxx`), shown to the visitor next to a friendly message and written to the PHP error log with technical context (never field values). Field values are never logged.

| Code | Meaning |
|---|---|
| PF-E1001 | Shortcode misconfigured (missing id/fields) |
| PF-E1101 | Required field empty |
| PF-E1102 | Invalid email |
| PF-E1103 | Invalid phone |
| PF-E2001 | Nonce missing/invalid |
| PF-E2002 | Honeypot filled (bot) |
| PF-E2003 | Submitted too fast (bot) |
| PF-E2004 | Time-trap token forged or expired |
| PF-E2005 | Rate limit exceeded |
| PF-E2006 | Turnstile token missing |
| PF-E2007 | Turnstile rejected the visitor |
| PF-E2008 | Turnstile API unreachable — submission accepted (fail-open, logged) |
| PF-E3001 | Database insert failed |
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

## Roadmap

- v0.2: optional Akismet content check, per-form notification recipient
- Not planned: drag-and-drop builder, conditional logic, payments, multi-step. If you need those, use a vendor plugin — that is their job, not this one's.

## License

MIT — see [LICENSE](LICENSE).
