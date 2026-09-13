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

/**
 * Insert a lead. Returns the new row id, or WP_Error PF-E3001.
 *
 * @param string $form_id Form identifier.
 * @param array  $values  Sanitized field values.
 * @return int|WP_Error
 */
function petit_form_store_lead( $form_id, $values ) {
	global $wpdb;

	// The data column is TEXT (64 KB). Several long textareas with 4-byte
	// UTF-8 could exceed it and be silently truncated into invalid JSON.
	// Reject instead: a lead stored whole is worth more than a giant message.
	$json = wp_json_encode( $values, JSON_UNESCAPED_UNICODE );
	if ( strlen( (string) $json ) > 60000 ) {
		return new WP_Error( 'PF-E1104', 'Encoded payload exceeds 60 KB.' );
	}

	$inserted = $wpdb->insert(
		petit_form_table(),
		array(
			'form_id'    => $form_id,
			'data'       => $json,
			'ip_hash'    => petit_form_ip_hash(),
			'created_at' => current_time( 'mysql', true ),
		),
		array( '%s', '%s', '%s', '%s' )
	);

	if ( false === $inserted ) {
		return new WP_Error( 'PF-E3001', 'Database insert failed: ' . $wpdb->last_error );
	}
	return (int) $wpdb->insert_id;
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
