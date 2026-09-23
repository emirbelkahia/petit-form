<?php
/**
 * Submission pipeline. Order matters: cheap rejections first, storage before
 * side effects, and a post/redirect/get loop so refresh never re-submits.
 *
 * Pipeline: nonce -> traps -> local validation -> attempt limit -> Turnstile
 * -> store lead and pending notifications -> integration hook -> redirect.
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

	// 1. WordPress nonce. Guests share a nonce; this is not proof of humanity.
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
	if ( empty( $fields ) ) {
		petit_form_log( 'PF-E1001', 'Invalid field definition.' );
		petit_form_redirect_back( $back, $form_id, 'PF-E1001' );
	}
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

	// 4. Consume an attempt before any external request. Local typos do not
	// count; rejected CAPTCHA tokens do, so invalid tokens cannot flood HTTP.
	$rate = petit_form_rate_limit_check( $form_id );
	if ( is_wp_error( $rate ) ) {
		petit_form_log( $rate->get_error_code(), $rate->get_error_message() );
		petit_form_redirect_back( $back, $form_id, $rate->get_error_code() );
	}

	// 5. Optional Turnstile, within the attempt budget.
	$turnstile = petit_form_verify_turnstile( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( is_wp_error( $turnstile ) ) {
		petit_form_log( $turnstile->get_error_code(), $turnstile->get_error_message() );
		petit_form_redirect_back( $back, $form_id, $turnstile->get_error_code() );
	}

	// 6. Store the lead FIRST: the database is the source of truth,
	// email and webhook are best-effort side effects.
	$lead_id = petit_form_store_lead( $form_id, $values, $fields );
	if ( is_wp_error( $lead_id ) ) {
		petit_form_log( $lead_id->get_error_code(), $lead_id->get_error_message() );
		petit_form_redirect_back( $back, $form_id, $lead_id->get_error_code() );
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
 * Refresh public form tokens without submitting or storing a lead.
 */
function petit_form_handle_token_refresh() {
	$request = array();
	foreach ( array( 'pf_form_id', 'pf_fields', 'pf_definition_sig' ) as $key ) {
		$request[ $key ] = isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
	}
	$result = petit_form_refresh_tokens( $request );
	if ( is_wp_error( $result ) ) {
		petit_form_log( $result->get_error_code(), $result->get_error_message() );
		wp_send_json_error( array( 'code' => $result->get_error_code() ), 400 );
	}
	wp_send_json_success( $result );
}

/**
 * Monitoring probe: replay the checks of a real submission and answer in
 * JSON. Exists only when the site defines PETIT_FORM_PROBE_KEY. Never stores
 * a lead, queues a notification, fires petit_form_lead_created, consumes an
 * attempt or calls Turnstile.
 */
function petit_form_handle_probe() {
	$key = defined( 'PETIT_FORM_PROBE_KEY' ) ? PETIT_FORM_PROBE_KEY : '';
	if ( ! is_string( $key ) || '' === $key ) {
		wp_die( '', 404 );
	}
	$given = isset( $_SERVER['HTTP_X_PETIT_FORM_PROBE'] ) && is_string( $_SERVER['HTTP_X_PETIT_FORM_PROBE'] ) ? wp_unslash( $_SERVER['HTTP_X_PETIT_FORM_PROBE'] ) : '';
	if ( ! hash_equals( $key, $given ) ) {
		wp_send_json_error( null, 403 );
	}
	$checked = petit_form_probe_checks();
	if ( is_wp_error( $checked ) ) {
		wp_send_json_error( array( 'code' => $checked->get_error_code() ), 400 );
	}
	// Failures the checks cannot see; both also raise an admin warning.
	if ( get_option( 'petit_form_storage_error' ) ) {
		wp_send_json_error( array( 'code' => 'PF-E3001' ), 503 );
	}
	if ( '' !== petit_form_delivery_problem() ) {
		wp_send_json_error( array( 'code' => 'PF-E4003' ), 503 );
	}
	wp_send_json_success( array( 'ok' => true, 'turnstile' => 'skipped' ), 200 );
}

/**
 * Steps 1-3 of petit_form_handle_submit, read from $_POST the same way.
 * Keep both in sync when a check changes.
 *
 * @return true|WP_Error
 */
function petit_form_probe_checks() {
	$form_id = isset( $_POST['pf_form_id'] ) && is_string( $_POST['pf_form_id'] ) ? substr( sanitize_key( wp_unslash( $_POST['pf_form_id'] ) ), 0, 64 ) : '';
	$spec    = isset( $_POST['pf_fields'] ) && is_string( $_POST['pf_fields'] ) ? wp_unslash( $_POST['pf_fields'] ) : '';

	if ( ! $form_id || ! isset( $_POST['pf_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pf_nonce'] ) ), 'petit_form_submit_' . $form_id ) ) {
		return new WP_Error( 'PF-E2001', 'Nonce missing or invalid.' );
	}

	$trap_post = array(
		'pf_hp_x91' => isset( $_POST['pf_hp_x91'] ) && is_string( $_POST['pf_hp_x91'] ) ? wp_unslash( $_POST['pf_hp_x91'] ) : '',
		'pf_ts'     => isset( $_POST['pf_ts'] ) && is_string( $_POST['pf_ts'] ) ? wp_unslash( $_POST['pf_ts'] ) : '',
		'pf_sig'    => isset( $_POST['pf_sig'] ) && is_string( $_POST['pf_sig'] ) ? wp_unslash( $_POST['pf_sig'] ) : '',
		'pf_fields' => $spec,
	);
	$traps = petit_form_verify_traps( $trap_post, $form_id );
	if ( is_wp_error( $traps ) ) {
		return $traps;
	}

	$fields = petit_form_parse_fields( $spec );
	if ( empty( $fields ) ) {
		return new WP_Error( 'PF-E1001', 'Invalid field definition.' );
	}
	foreach ( $fields as $field ) {
		$raw   = isset( $_POST[ 'pf_f_' . $field['key'] ] ) && is_string( $_POST[ 'pf_f_' . $field['key'] ] ) ? wp_unslash( $_POST[ 'pf_f_' . $field['key'] ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$valid = petit_form_validate_value( $field, petit_form_sanitize_value( $raw, $field['type'] ) );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
	}
	return true;
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
