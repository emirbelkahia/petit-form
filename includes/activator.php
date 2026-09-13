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
		notify_data text DEFAULT NULL,
		notify_after bigint(20) unsigned DEFAULT NULL,
		notify_attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		KEY form_id (form_id),
		KEY created_at (created_at),
		KEY notify_after (notify_after)
	) {$charset};";

	dbDelta( $sql );

	// Verify columns as well as table existence before marking the upgrade done.
	$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
	if ( array_diff( array( 'id', 'form_id', 'data', 'ip_hash', 'created_at', 'notify_data', 'notify_after', 'notify_attempts' ), (array) $columns ) ) {
		update_option( 'petit_form_storage_error', true, false );
		petit_form_log( 'PF-E3002', 'Leads schema is incomplete; check database permissions.' );
		return false;
	}
	delete_option( 'petit_form_storage_error' );
	update_option( 'petit_form_db_version', PETIT_FORM_DB_VERSION );
	return true;
}
