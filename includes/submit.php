<?php
/**
 * Submission pipeline. Order matters: cheap rejections first, storage before
 * side effects, and a post/redirect/get loop so refresh never re-submits.
 *
 * Pipeline: nonce -> honeypot/time-trap -> sanitize + validate -> Turnstile
 * -> rate limit -> store lead -> notify email -> webhook -> redirect.
 *
 * @package PetitForm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * admin-post.php handler for every Petit Form submission.
 */
function petit_form_handle_submit() {
	$form_id = isset( $_POST['pf_form_id'] ) && is_string( $_POST['pf_form_id'] ) ? substr( sanitize_key( wp_unslash( $_POST['pf_form_id'] ) ), 0, 64 ) : '';
	$back    = isset( $_POST['pf_back'] ) && is_string( $_POST['pf_back'] ) ? esc_url_raw( wp_unslash( $_POST['pf_back'] ) ) : '';
	if ( '' === $back ) {
		$back = home_url( '/' );
	}
	// The spec is signed as-is: unslash (WordPress magic-quotes $_POST) but
	// never "sanitize" it — any byte difference invalidates the signature.
	$spec = isset( $_POST['pf_fields'] ) && is_string( $_POST['pf_fields'] ) ? wp_unslash( $_POST['pf_fields'] ) : '';

	// 1. Nonce: proves the request came from our form on this site (CSRF).
	if ( ! $form_id || ! isset( $_POST['pf_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pf_nonce'] ) ), 'petit_form_submit_' . $form_id ) ) {
		petit_form_log( 'PF-E2001', 'Nonce missing or invalid.' );
		petit_form_redirect_back( $back, $form_id, 'PF-E2001' );
	}

	// 2. Honeypot + time-trap. Values are unslashed BEFORE the signature
	//    check: an apostrophe in a field label must not break it.
	$trap_post = array(
		'pf_hp_x91'  => isset( $_POST['pf_hp_x91'] ) && is_string( $_POST['pf_hp_x91'] ) ? wp_unslash( $_POST['pf_hp_x91'] ) : '',
		'pf_ts'      => isset( $_POST['pf_ts'] ) && is_string( $_POST['pf_ts'] ) ? wp_unslash( $_POST['pf_ts'] ) : '',
		'pf_sig'     => isset( $_POST['pf_sig'] ) && is_string( $_POST['pf_sig'] ) ? wp_unslash( $_POST['pf_sig'] ) : '',
		'pf_fields'  => $spec,
	);
	$traps = petit_form_verify_traps( $trap_post, $form_id );
	if ( is_wp_error( $traps ) ) {
		petit_form_log( $traps->get_error_code(), $traps->get_error_message() );
		petit_form_redirect_back( $back, $form_id, $traps->get_error_code() );
	}

	// 3. Sanitize + validate every declared field. Unknown POST keys are ignored.
	//    The spec is trustworthy: its integrity is proven by the time-trap
	//    signature verified in step 2.
	$fields = petit_form_parse_fields( $spec );
	$values = array();
	foreach ( $fields as $field ) {
		$raw    = isset( $_POST[ 'pf_f_' . $field['key'] ] ) && is_string( $_POST[ 'pf_f_' . $field['key'] ] ) ? wp_unslash( $_POST[ 'pf_f_' . $field['key'] ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$value  = petit_form_sanitize_value( $raw, $field['type'] );
		$valid  = petit_form_validate_value( $field, $value );
		if ( is_wp_error( $valid ) ) {
			petit_form_log( $valid->get_error_code(), $valid->get_error_message() );
			petit_form_redirect_back( $back, $form_id, $valid->get_error_code() );
		}
		$values[ $field['key'] ] = $value;
	}

	// 4. Turnstile (only when configured). After local validation: the
	//    outbound HTTP call is the most expensive check, keep it for
	//    plausible submissions only.
	$turnstile = petit_form_verify_turnstile( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( is_wp_error( $turnstile ) ) {
		petit_form_log( $turnstile->get_error_code(), $turnstile->get_error_message() );
		petit_form_redirect_back( $back, $form_id, $turnstile->get_error_code() );
	}

	// 5. Rate limit AFTER validation: a human fixing a typo must not burn
	//    their quota; only plausible submissions count.
	$rate = petit_form_rate_limit_check( $form_id );
	if ( is_wp_error( $rate ) ) {
		petit_form_log( $rate->get_error_code(), $rate->get_error_message() );
		petit_form_redirect_back( $back, $form_id, $rate->get_error_code() );
	}

	// 6. Store the lead FIRST: the database is the source of truth,
	// email and webhook are best-effort side effects.
	$lead_id = petit_form_store_lead( $form_id, $values );
	if ( is_wp_error( $lead_id ) ) {
		petit_form_log( $lead_id->get_error_code(), $lead_id->get_error_message() );
		petit_form_redirect_back( $back, $form_id, $lead_id->get_error_code() );
	}

	// 7. Notification email (failure logged, never blocks the visitor).
	$mail = petit_form_send_notification( $form_id, $values, $lead_id, $fields );
	if ( is_wp_error( $mail ) ) {
		petit_form_log( $mail->get_error_code(), $mail->get_error_message() );
	}

	// 8. Generic outbound webhook (failure logged, never blocks).
	$hook = petit_form_send_webhook( $form_id, $values, $lead_id );
	if ( is_wp_error( $hook ) ) {
		petit_form_log( $hook->get_error_code(), $hook->get_error_message() );
	}

	/**
	 * Fires after a lead is stored. Site-specific plugins (CRM sync, etc.)
	 * should hook here rather than editing this plugin.
	 *
	 * @param int    $lead_id Lead row id.
	 * @param string $form_id Form identifier from the shortcode.
	 * @param array  $values  Sanitized field values, keyed by field key.
	 */
	do_action( 'petit_form_lead_created', $lead_id, $form_id, $values );

	petit_form_redirect_back( $back, $form_id, '' );
}

/**
 * Redirect helper: back to the originating page with a status query arg.
 */
function petit_form_redirect_back( $url, $form_id, $error_code ) {
	$args = array(
		'pf_status' => $error_code ? 'error' : 'ok',
		'pf_form'   => $form_id,
	);
	if ( $error_code ) {
		$args['pf_error'] = $error_code;
	}
	// Strip any previous pf_* args to keep URLs clean on repeated attempts.
	$url = remove_query_arg( array( 'pf_status', 'pf_form', 'pf_error' ), $url );
	wp_safe_redirect( add_query_arg( $args, $url ) . '#pf-' . $form_id );
	exit;
}
