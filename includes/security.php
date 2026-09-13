<?php
/**
 * Security layer: nonce, honeypot, time-trap, rate limiting, optional Turnstile.
 *
 * Every rejection carries a stable error code (PF-Exxxx) so that a log line
 * stays diagnosable years from now. Codes are documented in README.md.
 *
 * @package PetitForm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether Cloudflare Turnstile is configured (both keys present).
 */
function petit_form_turnstile_enabled() {
	$site   = (string) get_option( 'petit_form_turnstile_site_key', '' );
	$secret = (string) get_option( 'petit_form_turnstile_secret_key', '' );
	return '' !== $site && '' !== $secret;
}

/**
 * Visitor IP. Defaults to REMOTE_ADDR only: X-Forwarded-For is forgeable and
 * must never be trusted blindly. Sites behind a known reverse proxy can set
 * the real source with the `petit_form_client_ip` filter.
 */
function petit_form_client_ip() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
	/**
	 * Override the client IP source (e.g. read a trusted proxy header).
	 * @param string $ip IP from REMOTE_ADDR.
	 */
	return (string) apply_filters( 'petit_form_client_ip', $ip );
}

/**
 * Hash the visitor IP with a salt. We never store raw IPs (GDPR), but a
 * stable hash lets us rate-limit and investigate abuse patterns.
 */
function petit_form_ip_hash() {
	return hash_hmac( 'sha256', petit_form_client_ip(), wp_salt( 'nonce' ) );
}

/**
 * Sign the time-trap token. The signature binds the timestamp, the form id
 * AND the field specification: a visitor cannot alter the fields spec
 * (bypassing "required", changing types) without invalidating the signature.
 */
function petit_form_time_trap_sign( $timestamp, $form_id, $spec = '' ) {
	return hash_hmac( 'sha256', $timestamp . '|' . $form_id . '|' . $spec, wp_salt( 'nonce' ) );
}

/**
 * Render the hidden anti-bot fields: honeypot + signed time-trap.
 * The honeypot is invisible to humans (CSS + aria-hidden + tabindex) but
 * bots that fill every field will populate it.
 */
function petit_form_render_trap_fields( $form_id, $spec = '' ) {
	$ts  = time();
	$sig = petit_form_time_trap_sign( $ts, $form_id, $spec );
	?>
	<div class="pf-trap" aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;height:1px;width:1px;overflow:hidden;">
		<label><?php esc_html_e( 'Leave this field empty', 'petit-form' ); ?>
			<input type="text" name="pf_company_url" value="" tabindex="-1" autocomplete="off" />
		</label>
	</div>
	<input type="hidden" name="pf_ts" value="<?php echo esc_attr( $ts ); ?>" />
	<input type="hidden" name="pf_sig" value="<?php echo esc_attr( $sig ); ?>" />
	<?php
}

/**
 * Verify the anti-bot traps. Returns true when the submission looks human,
 * or a WP_Error with a stable code when it does not.
 *
 * @param array $post Sanitized-ish $_POST subset.
 * @return true|WP_Error
 */
function petit_form_verify_traps( $post, $form_id ) {
	// Honeypot: must be empty.
	if ( ! empty( $post['pf_company_url'] ) ) {
		return new WP_Error( 'PF-E2002', 'Honeypot field was filled.' );
	}

	// Time-trap: signature must match and elapsed time must be plausible.
	// The signature binds ts + form_id + fields spec: tampering with the spec
	// (removing "required", changing types) invalidates it -> PF-E2004.
	$ts   = isset( $post['pf_ts'] ) ? (int) $post['pf_ts'] : 0;
	$sig  = isset( $post['pf_sig'] ) ? (string) $post['pf_sig'] : '';
	$spec = isset( $post['pf_fields'] ) ? (string) $post['pf_fields'] : '';
	if ( ! $ts || ! hash_equals( petit_form_time_trap_sign( $ts, $form_id, $spec ), $sig ) ) {
		return new WP_Error( 'PF-E2004', 'Time-trap signature mismatch (ts, form id or fields spec tampered).' );
	}
	$elapsed = time() - $ts;
	$min     = (int) get_option( 'petit_form_min_seconds', 3 );
	if ( $elapsed < $min ) {
		return new WP_Error( 'PF-E2003', sprintf( 'Submitted too fast (%ds < %ds).', $elapsed, $min ) );
	}
	// 24h: aligned with the WordPress nonce lifetime, so a page served from a
	// page cache keeps a working form as long as its nonce is valid.
	if ( $elapsed > DAY_IN_SECONDS ) {
		return new WP_Error( 'PF-E2004', 'Form token expired (>24h old).' );
	}

	return true;
}

/**
 * Per-IP + per-form rate limiting using transients. Default: 5 submissions
 * per hour. Returns true when allowed, WP_Error PF-E2005 when exceeded.
 *
 * @return true|WP_Error
 */
function petit_form_rate_limit_check( $form_id ) {
	$max    = (int) get_option( 'petit_form_rate_max', 5 );
	$window = (int) get_option( 'petit_form_rate_window', HOUR_IN_SECONDS );
	// One bucket per (IP hash, form): hash the pair so neither part is truncated.
	$key    = 'pf_rl_' . substr( hash_hmac( 'sha256', petit_form_ip_hash() . '|' . $form_id, wp_salt( 'nonce' ) ), 0, 32 );

	$count = get_transient( $key );
	if ( false === $count ) {
		set_transient( $key, 1, $window );
		return true;
	}
	if ( (int) $count >= $max ) {
		return new WP_Error( 'PF-E2005', sprintf( 'Rate limit exceeded (%d/%d in %ds).', (int) $count + 1, $max, $window ) );
	}
	set_transient( $key, (int) $count + 1, $window );
	return true;
}

/**
 * Verify a Cloudflare Turnstile token server-side. Only called when the
 * feature is configured. Tokens are single-use and expire after 300s.
 *
 * Design decision: if the Cloudflare API is unreachable, we FAIL OPEN (accept
 * the submission, log PF-E2008). Losing a real lead costs more than a spam
 * wave during a Cloudflare outage. Documented in README.md.
 *
 * @return true|WP_Error
 */
function petit_form_verify_turnstile( $post ) {
	if ( ! petit_form_turnstile_enabled() ) {
		return true;
	}
	$token = isset( $post['cf-turnstile-response'] ) ? (string) $post['cf-turnstile-response'] : '';
	if ( '' === $token ) {
		return new WP_Error( 'PF-E2006', 'Turnstile token missing.' );
	}
	$response = wp_remote_post(
		'https://challenges.cloudflare.com/turnstile/v0/siteverify',
		array(
			'timeout' => 10,
			'body'    => array(
				'secret'   => get_option( 'petit_form_turnstile_secret_key', '' ),
				'response' => $token,
				'remoteip' => petit_form_client_ip(),
			),
		)
	);
	if ( is_wp_error( $response ) ) {
		petit_form_log( 'PF-E2008', 'Turnstile API unreachable, failing open: ' . $response->get_error_message() );
		return true;
	}
	if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		petit_form_log( 'PF-E2008', 'Turnstile API returned HTTP ' . wp_remote_retrieve_response_code( $response ) . ', failing open.' );
		return true;
	}
	$json = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( empty( $json['success'] ) ) {
		$codes = isset( $json['error-codes'] ) ? implode( ',', (array) $json['error-codes'] ) : 'unknown';
		return new WP_Error( 'PF-E2007', 'Turnstile rejected: ' . $codes );
	}
	return true;
}

/**
 * Central security logger. One line, one code, grep-able in five years.
 * Always on: only codes and technical context are logged, never field
 * values (PII), so production logging is safe. Define the constant
 * PETIT_FORM_LOG to false in wp-config.php to silence it.
 */
function petit_form_log( $code, $message ) {
	if ( defined( 'PETIT_FORM_LOG' ) && ! PETIT_FORM_LOG ) {
		return;
	}
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	error_log( sprintf( '[petit-form %s] %s (form=%s ip=%s)', $code, $message, isset( $_POST['pf_form_id'] ) ? sanitize_text_field( wp_unslash( $_POST['pf_form_id'] ) ) : '?', petit_form_ip_hash() ) );
}
