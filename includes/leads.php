<?php
/**
 * Leads storage. One custom table, JSON payload (never serialized PHP),
 * prepared statements everywhere. Raw IPs are never stored (GDPR): only an
 * HMAC hash for abuse forensics.
 *
 * @package PetitForm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Full table name with the site prefix.
 */
function petit_form_table() {
	global $wpdb;
	return $wpdb->prefix . 'petitform_leads';
}

/** Check only during upgrades, admin visits, or a failed insert. */
function petit_form_table_exists() {
	global $wpdb;
	$table = petit_form_table();
	return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
}

/**
 * Insert a lead. Returns the new row id, or WP_Error PF-E3001.
 *
 * @param string $form_id Form identifier.
 * @param array  $values  Sanitized field values.
 * @param array  $fields  Field definitions used to select Reply-To keys.
 * @return int|WP_Error
 */
function petit_form_store_lead( $form_id, $values, $fields = array() ) {
	global $wpdb;

	// ASCII JSON also preserves emoji on legacy three-byte UTF-8 databases.
	$json = wp_json_encode( $values );
	if ( false === $json || strlen( $json ) > 60000 ) {
		return new WP_Error( 'PF-E1104', 'Encoded payload is invalid or exceeds 60 KB.' );
	}
	// Delivery only needs the first populated email/name keys, not the labels.
	$reply_fields = array();
	foreach ( $fields as $field ) {
		if ( in_array( $field['type'], array( 'email', 'name' ), true ) && ! isset( $reply_fields[ $field['type'] ] ) && ! empty( $values[ $field['key'] ] ) ) {
			$reply_fields[ $field['type'] ] = array( 'key' => $field['key'], 'type' => $field['type'] );
		}
	}
	$row = array(
		'form_id'      => $form_id,
		'data'         => $json,
		'ip_hash'      => petit_form_ip_hash(),
		'created_at'   => current_time( 'mysql', true ),
		'notify_data'  => wp_json_encode( array(
			'fields'  => array_values( $reply_fields ),
			'pending' => array( 'email' => true, 'webhook' => '' !== (string) get_option( 'petit_form_webhook_url', '' ) ),
		) ),
		'notify_after' => time(),
	);
	$formats = array( '%s', '%s', '%s', '%s', '%s', '%d' );
	$inserted = $wpdb->insert( petit_form_table(), $row, $formats );
	if ( false === $inserted && ! petit_form_table_exists() ) {
		// Repair an absent table once. Existing rows and other DB failures are untouched.
		require_once PETIT_FORM_DIR . 'includes/activator.php';
		if ( petit_form_create_table() ) {
			$inserted = $wpdb->insert( petit_form_table(), $row, $formats );
		}
	}
	if ( false === $inserted ) {
		update_option( 'petit_form_storage_error', true, false );
		return new WP_Error( 'PF-E3001', 'Database insert failed.' );
	}
	$lead_id = (int) $wpdb->insert_id;
	delete_option( 'petit_form_storage_error' );
	return $lead_id;
}

/**
 * Count leads, optionally filtered by form.
 */
function petit_form_count_leads( $form_id = '' ) {
	global $wpdb;
	$table = petit_form_table();
	if ( '' !== $form_id ) {
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE form_id = %s", $form_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

/**
 * Fetch a page of leads, newest first.
 *
 * @return array<int,object>
 */
function petit_form_get_leads( $form_id = '', $limit = 50, $offset = 0 ) {
	global $wpdb;
	$table = petit_form_table();
	$limit = max( 1, min( 500, (int) $limit ) );
	$off   = max( 0, (int) $offset );

	if ( '' !== $form_id ) {
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE form_id = %s ORDER BY id DESC LIMIT %d OFFSET %d", $form_id, $limit, $off ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}
	return $wpdb->get_results(
		$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $off ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	);
}

/**
 * Stream leads for export in batches of 1 000 (keyset pagination), so a
 * large table never blows the memory limit on shared hosting.
 *
 * @param string   $form_id  Optional form filter.
 * @param callable $callback Receives one lead object per row.
 */
function petit_form_each_lead_for_export( $form_id, $callback ) {
	global $wpdb;
	$table   = petit_form_table();
	$last_id = PHP_INT_MAX;

	while ( true ) {
		if ( '' !== $form_id ) {
			$batch = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE form_id = %s AND id < %d ORDER BY id DESC LIMIT 1000", $form_id, $last_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
		} else {
			$batch = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE id < %d ORDER BY id DESC LIMIT 1000", $last_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
		}
		if ( empty( $batch ) ) {
			return;
		}
		foreach ( $batch as $lead ) {
			$callback( $lead );
		}
		$last_id = (int) end( $batch )->id;
		if ( count( $batch ) < 1000 ) {
			return;
		}
	}
}

/**
 * Delete one lead by id. Returns true on success.
 */
function petit_form_delete_lead( $lead_id ) {
	global $wpdb;
	return (bool) $wpdb->delete( petit_form_table(), array( 'id' => (int) $lead_id ), array( '%d' ) );
}
