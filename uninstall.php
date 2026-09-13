<?php
/**
 * Uninstall: remove plugin data ONLY when the site owner explicitly opted in
 * (setting "Delete data on uninstall"). Leads are business data; the safe
 * default is to keep them.
 *
 * @package PetitForm
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! get_option( 'petit_form_delete_data_on_uninstall', false ) ) {
	return;
}

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}petitform_leads" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

$options = array(
	'petit_form_notify_email',
	'petit_form_webhook_url',
	'petit_form_webhook_header_name',
	'petit_form_webhook_header_value',
	'petit_form_turnstile_site_key',
	'petit_form_turnstile_secret_key',
	'petit_form_rate_max',
	'petit_form_rate_window',
	'petit_form_min_seconds',
	'petit_form_delete_data_on_uninstall',
	'petit_form_db_version',
);
foreach ( $options as $option ) {
	delete_option( $option );
}

// Rate-limit transients.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_pf\_rl\_%' OR option_name LIKE '\_transient\_timeout\_pf\_rl\_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
