<?php
/**
 * Notification email. Best-effort side effect: a failure is logged
 * (PF-E4001) but never blocks the visitor, because the lead is already
 * stored in the database.
 *
 * @package PetitForm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Send the notification email to the configured recipient.
 *
 * Header-injection safe: name/email values were sanitized on the way in
 * (single-line, tags stripped), and we additionally strip CR/LF before
 * putting anything into a header.
 *
 * @return true|WP_Error
 */
function petit_form_send_notification( $form_id, $values, $lead_id = 0, $fields = array() ) {
	$to = (string) get_option( 'petit_form_notify_email', '' );
	if ( '' === $to ) {
		$to = (string) get_option( 'admin_email' ); // fallback if the option was saved empty
	}
	if ( '' === $to || ! is_email( $to ) ) {
		return new WP_Error( 'PF-E4001', 'Notification recipient is not a valid email address.' );
	}

	$subject = sprintf(
		/* translators: 1: form identifier, 2: site name */
		__( '[%1$s] New lead on %2$s', 'petit-form' ),
		$form_id,
		wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES )
	);

	$lines = array();
	foreach ( $values as $key => $value ) {
		$lines[] = ucfirst( str_replace( array( '_', '-' ), ' ', $key ) ) . ' : ' . $value;
	}
	$lines[] = '';
	$lines[] = sprintf( __( 'Lead #%d — stored in WordPress (Leads menu).', 'petit-form' ), (int) $lead_id );
	$body    = implode( "\n", $lines );

	$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

	// Reply-To from the first email-typed and name-typed fields of the spec,
	// whatever their keys ("email", "courriel", "name", "prenom"...).
	$reply_email = '';
	$reply_name  = '';
	foreach ( (array) $fields as $field ) {
		if ( 'email' === $field['type'] && ! $reply_email && ! empty( $values[ $field['key'] ] ) && is_email( $values[ $field['key'] ] ) ) {
			$reply_email = $values[ $field['key'] ];
		}
		if ( 'name' === $field['type'] && ! $reply_name && ! empty( $values[ $field['key'] ] ) ) {
			$reply_name = petit_form_strip_crlf( $values[ $field['key'] ] );
		}
	}
	if ( $reply_email ) {
		$headers[] = 'Reply-To: ' . ( $reply_name ? $reply_name . ' ' : '' ) . '<' . $reply_email . '>';
	}

	$sent = wp_mail( $to, $subject, $body, $headers );
	if ( ! $sent ) {
		return new WP_Error( 'PF-E4001', 'wp_mail() returned false.' );
	}
	return true;
}

/**
 * Remove CR/LF: never let user input break out of a mail header.
 */
function petit_form_strip_crlf( $value ) {
	return trim( str_replace( array( "\r", "\n" ), ' ', (string) $value ) );
}

/**
 * Generic outbound webhook: POSTs the lead as JSON to a configured URL.
 * Useful for CRMs and automation tools. Site-specific integrations should
 * prefer the `petit_form_lead_created` action hook instead.
 *
 * @return true|WP_Error
 */
function petit_form_send_webhook( $form_id, $values, $lead_id ) {
	$url = (string) get_option( 'petit_form_webhook_url', '' );
	if ( '' === $url ) {
		return true; // not configured: nothing to do
	}

	$headers = array( 'Content-Type' => 'application/json' );
	$h_name  = (string) get_option( 'petit_form_webhook_header_name', '' );
	$h_value = (string) get_option( 'petit_form_webhook_header_value', '' );
	if ( '' !== $h_name && '' !== $h_value ) {
		$headers[ $h_name ] = $h_value;
	}

	$response = wp_remote_post(
		$url,
		array(
			'timeout' => 15,
			'headers' => $headers,
			'body'    => wp_json_encode(
				array(
					'lead_id' => $lead_id,
					'form'    => $form_id,
					'fields'  => $values,
					'site'    => home_url(),
				),
				JSON_UNESCAPED_UNICODE
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'PF-E4101', 'Webhook request failed: ' . $response->get_error_message() );
	}
	$code = wp_remote_retrieve_response_code( $response );
	if ( $code >= 400 ) {
		return new WP_Error( 'PF-E4101', sprintf( 'Webhook returned HTTP %d.', $code ) );
	}
	return true;
}
