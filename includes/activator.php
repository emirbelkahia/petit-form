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
	update_option( 'petit_form_db_version', PETIT_FORM_VERSION );
}
