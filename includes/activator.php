<?php
/**
 * Activation: create the leads table via dbDelta (idempotent, upgrade-safe).
 *
 * @package PetitForm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create or update the leads table.
 */
function petit_form_create_table() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table   = petit_form_table();
	$charset = $wpdb->get_charset_collate();

	// data: JSON payload (never PHP-serialized). ip_hash: HMAC only, no raw IP.
	$sql = "CREATE TABLE {$table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		form_id varchar(64) NOT NULL,
		data text NOT NULL,
		ip_hash varchar(64) NOT NULL DEFAULT '',
		created_at datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY form_id (form_id),
		KEY created_at (created_at)
	) {$charset};";

	dbDelta( $sql );

	// dbDelta fails silently (e.g. MySQL user without CREATE). Verify, log a
	// stable code, and do NOT write the version: admin_init will retry.
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
		if ( function_exists( 'petit_form_log' ) ) {
			petit_form_log( 'PF-E3002', 'Leads table creation failed: ' . $wpdb->last_error );
		}
		return;
	}
	update_option( 'petit_form_db_version', PETIT_FORM_VERSION );
}
