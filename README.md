# Petit Form

Small shortcode-based contact forms for WordPress. Leads are stored in your database and accessible under **Leads** in wp-admin. No bundled libraries, build step, tracking, file uploads, or external account required. Optional Cloudflare Turnstile and a JSON webhook are available.

Requires WordPress 6.0+ and PHP 7.4+. For production, keep WordPress updated and use a [supported PHP release](https://www.php.net/supported-versions.php).

## Is Petit Form for you?

For site owners and developers who need a few simple contact or lead forms, local records, and email or webhook notifications, and are comfortable configuring shortcodes and styling with CSS.

Single-site only. Visual builders, conditional logic, multi-step forms, uploads, payments and a large integration catalog are outside its scope.

The features overlap with existing form plugins. Petit Form exists to keep this limited scope in a small codebase that you can inspect and maintain yourself. If your current solution already fits, switching may bring no practical benefit.

## Install and use

Use the installable ZIP produced by GitHub Actions, or build one from a committed revision:

```sh
git archive --format=zip --prefix=petit-form/ -o /tmp/petit-form.zip HEAD \
  petit-form.php uninstall.php includes assets languages README.md LICENSE
```

Upload the ZIP under **Plugins → Add New → Upload Plugin**, activate **Petit Form**, and configure notifications and anti-spam under **Leads → Settings**. Tests and CI files are excluded from this ZIP. Copying the full repository also copies those files to disk, though the plugin never loads or runs them.

Add a shortcode to a page:

```text
[petit-form id="contact" fields="name:required, email:required, telephone, message:textarea:required, consent:checkbox:required:I accept the privacy policy" placeholders="name=Your name|email=you@example.com|telephone=Phone (optional)|message=How can I help?"]
```

- `id` is required. Use distinct IDs for forms on the same page.
- `fields` defaults to required name, email and message fields. Syntax: `key[:type][:required][:Label]`, separated by commas. Commas and colons are reserved separators.
- Types: `text`, `name`, `email`, `tel`, `textarea`, `checkbox`. Common keys infer their type, including `prenom`, `courriel`, `telephone`, `message`, `rgpd` and `consent`.
- Keys are normalized to lowercase ASCII; empty or duplicate normalized keys invalidate the form.
- Optional `placeholders="key=Hint|other=Another hint"` adds declarative hints to text fields and textareas. Pipes and equals signs are reserved separators. Invalid or duplicate entries discard the placeholder map without disabling the form. Placeholder text is presentation only: labels remain visible and submissions are unchanged.
- Optional `submit="Send"` and `success="Thank you!"` attributes change the button and confirmation.

Petit Form keeps native browser constraint validation and supplies its required
field, checkbox and email messages through the active WordPress locale. It
still works without JavaScript, in which case the browser supplies its own
validation text.

Pages can remain open after the original WordPress nonce expires. When a form
is more than ten hours old, the small front-end helper refreshes its nonce and
signed time token before allowing the existing native POST to continue. The
refresh authenticates the exact public form definition and never stores a lead
or invokes a notification transport. Repeated clicks share one refresh and
resume one submission. If refresh is unavailable, the form keeps the visitor's
entered values in place and shows the normal expiry guidance.

Submissions use a server-side POST/redirect/get loop and return to the form
fragment. On that one status response, Petit Form disables document-wide
smooth scrolling so long pages do not visibly animate from the top back to the
form. Anchor links on that response also jump instantly. Subsequent page loads
keep the theme's scroll behavior. The `petit_form_disable_status_scroll` filter
turns the override off (see Customization).

Text/name/email fields allow up to 255 characters, telephone fields 32, and textareas 10,000. Telephone validation accepts 6–15 digits with spaces and `+().-` formatting. Email addresses must pass WordPress validation; unsupported internationalized addresses are rejected without rewriting them. The complete stored JSON is limited to 60 KB, including Unicode escapes.

The admin list supports filtering, individual deletion and CSV export. Dates use the site's timezone. CSV exports escape formula prefixes and process rows in batches.

## Notifications

The lead and its pending notifications are saved together. The visitor's confirmation means the lead was stored; delivery happens separately through **WP-Cron**.

Asynchronous delivery keeps slow mail/webhook calls out of the visitor's request and allows recovery from temporary transport failures. We accept the added delivery state, retry logic and worker coordination, plus the need to keep cron running. The existing leads table and native WordPress scheduler avoid a separate queue service or library.

- Email uses `wp_mail()` and your site's existing mail transport, including any SMTP plugin. The configured recipient defaults to the site's admin email. Reply-To uses the first populated email field and, when present, a name field.
- The optional webhook receives JSON with `lead_id`, `form`, `fields` and `site`. An optional authentication header is supported. Only HTTP 2xx counts as success; redirects are not followed. Use an HTTPS endpoint.
- A worker scheduled every minute handles up to five leads per run. Failed channels are retried after five minutes, with at most three attempts per lead. A successful channel is not intentionally resent when the other fails. Pending and stopped deliveries appear in the leads list; details go to the PHP error log.
- Delivery uses the current settings. Disabling the webhook cancels its pending sends. Old leads are not notified again on upgrade.

WP-Cron depends on site visits unless your host runs it separately. On a quiet site, delivery can be delayed; if cron stops running, notifications remain pending. To avoid relying on visits, configure your host's scheduler to run WordPress cron every minute. An external scheduler is required if `DISABLE_WP_CRON` is set.

Administrators see **PF-E4003** on the Dashboard, Leads and Settings screens if the notification task is missing or more than 15 minutes overdue, with instructions to ask the host to check cron. The warning does not appear on unrelated admin screens. It clears when the schedule recovers. `DISABLE_WP_CRON` alone does not trigger it: an external scheduler may be working correctly. This checks the schedule, not successful execution or inbox delivery. See the [WordPress cron setup guide](https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/) for host configuration.

After the final failed attempt, automatic retries stop; the lead remains available in wp-admin. A server crash between sending and recording success can cause duplicate notifications. Webhook receivers should deduplicate by `site` and `lead_id`. Mail transport acceptance does not prove inbox delivery.

## Security and deployment

- Every submission requires a WordPress nonce and an HMAC signature covering timestamp, form ID and field definition. These prevent field-definition tampering. A separate HMAC binds public refresh requests to the exact rendered form definition. Nonce inputs keep the shared POST name `pf_nonce` while their HTML IDs include the form ID, so multiple forms can coexist in one valid document. Public tokens can be fetched and replayed; WordPress guests share a nonce by default, so the nonce does not establish a visitor's identity or prove humanity.
- A honeypot and minimum fill time (default three seconds) reject basic automation. Signed forms expire after 24 hours; WordPress nonces normally expire after 12–24 hours.
- The default quota is **10 locally valid attempts per IP and form per fixed UTC hour**, consumed atomically in the database before Turnstile. Local field-validation errors do not count; rejected CAPTCHA attempts and subsequent storage failures do. Settings allow 1–100 attempts per 60–86,400-second window. A burst can span two adjacent windows; this is not DDoS protection.
- Turnstile is enabled only when both keys are configured. Invalid tokens and non-200 responses below 500 are rejected. Network errors and HTTP 5xx **fail open**, accepting within the local quota and logging PF-E2008.
- SQL values are parameterized, rendered values are escaped, and admin read/export/delete operations require `manage_options`; exports and deletions also require nonces.
- The monitoring probe (`action=petit_form_probe`, key in the `X-Petit-Form-Probe` header) exists only when `PETIT_FORM_PROBE_KEY` is defined in `wp-config.php`; it replays the submission checks but stores nothing and sends nothing.

Check these deployment conditions:

1. Keep form-page cache lifetime below 12 hours, including any CDN cache. Six hours gives a practical margin for WordPress nonces. Purge cached forms after changing their definition or upgrading. Token refresh protects pages left open and cached HTML that outlives its intended expiry; it does not replace a bounded cache policy.
2. Allow anonymous POST requests to `/wp-admin/admin-post.php`.
3. `REMOTE_ADDR` is the default IP source. Behind a trusted proxy, configure `petit_form_client_ip` to resolve the real client safely. Never blindly trust `X-Forwarded-For`; otherwise visitors may share the proxy's quota. IPv6 addresses share a bucket per /64.
4. Verify an actual submission, the saved row, and notification delivery after deployment. Keep a restorable database backup. Schema upgrades run on admin requests and delivery runs; missing tables are also checked during admin visits and repaired once after a failed insert. Recreating a missing table cannot recover its old rows.

## Data and limitations

Leads remain until individually deleted. Raw IPs are not stored in the plugin's tables; salted HMACs are retained with leads and in logs. Turnstile receives the client IP when enabled. Server logs and other plugins have their own data policies. Retention and privacy-request handling remain the site owner's responsibility; there is no automatic lead purge or WordPress privacy exporter/eraser.

Uninstall preserves leads and settings by default. Enable **Delete data on uninstall** to remove them. Scheduled delivery is cleared on deactivation; pending leads resume after reactivation.

A validation error clears entered values; duplicate submissions can create duplicate leads. Additional integrations are added only when a concrete need justifies their maintenance.

## Customization

Use theme CSS to override the `.petit-form` and `.pf-` selectors. Visitor messages, the post-submit scroll override and custom integrations use WordPress hooks:

```php
add_filter( 'petit_form_user_message', function ( $message, $code ) {
    return 'PF-E2005' === $code ? 'Please try again later.' : $message;
}, 10, 2 );

// Keep the theme's smooth scrolling on status responses.
add_filter( 'petit_form_disable_status_scroll', '__return_false' );

add_action( 'petit_form_lead_created', function ( $lead_id, $form_id, $values ) {
    // Runs synchronously after storage. Keep custom handlers fast.
}, 10, 3 );
```

## Diagnostics

Rejections show a friendly message and a stable code. PHP logs include codes, technical context, form identifiers and IP hashes; submission field values are not deliberately logged. Define `PETIT_FORM_LOG` as `false` in `wp-config.php` to disable plugin logging.

| Code | Meaning |
|---|---|
| PF-E1001 | Missing form ID or unusable field definition |
| PF-E1101 | Required field empty |
| PF-E1102 | Invalid email |
| PF-E1103 | Invalid telephone |
| PF-E1104 | Field too long or encoded payload exceeds 60 KB |
| PF-E2001 | Missing or invalid nonce |
| PF-E2002 | Honeypot filled |
| PF-E2003 | Submitted too quickly |
| PF-E2004 | Invalid signature or expired signed form |
| PF-E2005 | Attempt quota exhausted |
| PF-E2006 | Turnstile token missing |
| PF-E2007 | Turnstile validation rejected |
| PF-E2008 | Turnstile unavailable; submission accepted within quota |
| PF-E2009 | Rate-limit database operation failed; submission rejected |
| PF-E3001 | Lead could not be stored |
| PF-E3002 | Leads schema creation or upgrade failed |
| PF-E4001 | Email transport rejected the notification |
| PF-E4002 | Notification scheduling, data or progress failure |
| PF-E4003 | Notification task missing or over 15 minutes overdue; administrator warning |
| PF-E4101 | Webhook request failed or returned a non-2xx status |

## Development

Run `php tests/smoke.php` for isolated logic tests and `node tests/browser.cjs` for the token-refresh browser behavior. For real WordPress/MariaDB tests, including concurrent quota and delivery workers, run `bash tests/run-integration.sh latest` against a disposable database server. Set `PF_DB_HOST`, `PF_DB_PORT`, `PF_DB_USER` and `PF_DB_PASSWORD` as needed (defaults: `127.0.0.1`, `3306`, `root`, empty). The runner creates and removes its own database and temporary WordPress installation; the database account needs create/drop privileges. PHP needs `mysqli`, `mbstring`, `dom` and `zip`; the runner also uses `curl`.

GitHub Actions runs lint, smoke and integration tests at the declared minimum versions and on current runtimes, and builds a ZIP from the tested commit. README, code comments and commit messages are written in English.

Built-in interface and error messages use English source strings. WordPress can display the bundled French translation according to its active locale. Shortcode labels are configurable.

## Built in the coding-agent era

Developed with coding agents. Documented constraints, stable error codes and repeatable tests help humans and agents work on the code. Agent-generated changes still require review and testing.

Personal-use project with best-effort maintenance. Issues and pull requests are read and welcome; response and fix times are not guaranteed. Report security issues through a [private GitHub security advisory](https://github.com/emirbelkahia/petit-form/security/advisories/new). Licensed under [MIT](LICENSE).
