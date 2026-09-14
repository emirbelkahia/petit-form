<?php
/**
 * Plugin Name:       Petit Form
 * Plugin URI:        https://github.com/emirbelkahia/petit-form
 * Description:       Simple, secure lead-capture forms. Shortcode-driven, leads stored in your database, no bloat, no upsells, no tracking.
 * Version:           0.2.5
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Emir Belkahia
 * Author URI:        https://github.com/emirbelkahia
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       petit-form
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PETIT_FORM_VERSION', '0.2.5' );
define( 'PETIT_FORM_DB_VERSION', '2' );
define( 'PETIT_FORM_FILE', __FILE__ );
define( 'PETIT_FORM_DIR', plugin_dir_path( __FILE__ ) );
define( 'PETIT_FORM_URL', plugin_dir_url( __FILE__ ) );

require_once PETIT_FORM_DIR . 'includes/security.php';
require_once PETIT_FORM_DIR . 'includes/fields.php';
require_once PETIT_FORM_DIR . 'includes/render.php';
require_once PETIT_FORM_DIR . 'includes/submit.php';
require_once PETIT_FORM_DIR . 'includes/leads.php';
require_once PETIT_FORM_DIR . 'includes/mail.php';
require_once PETIT_FORM_DIR . 'includes/delivery.php';
require_once PETIT_FORM_DIR . 'includes/admin.php';

/**
 * Activation: create the leads table.
 */
function petit_form_activate() {
	require_once PETIT_FORM_DIR . 'includes/activator.php';
	petit_form_create_table();
}
register_activation_hook( __FILE__, 'petit_form_activate' );

/**
 * Schema upgrades run on version change, not only on activation: a zip
 * upload update never fires the activation hook. Admin visits also check
 * for a missing table; failed inserts have a separate one-time repair.
 */
function petit_form_maybe_upgrade() {
	if ( get_option( 'petit_form_db_version' ) !== PETIT_FORM_DB_VERSION || ( current_user_can( 'manage_options' ) && ! petit_form_table_exists() ) ) {
		require_once PETIT_FORM_DIR . 'includes/activator.php';
		petit_form_create_table();
	}
}
add_action( 'admin_init', 'petit_form_maybe_upgrade' );

add_filter( 'cron_schedules', 'petit_form_cron_schedules' );
add_action( 'init', 'petit_form_schedule_delivery' );
add_action( 'petit_form_deliver_pending', 'petit_form_deliver_pending' );
register_deactivation_hook( __FILE__, 'petit_form_deactivate' );

/**
 * Load translations.
 */
function petit_form_load_textdomain() {
	load_plugin_textdomain( 'petit-form', false, dirname( plugin_basename( PETIT_FORM_FILE ) ) . '/languages' );
}
add_action( 'init', 'petit_form_load_textdomain' );

// Front-end form rendering.
add_shortcode( 'petit-form', 'petit_form_shortcode' );
// After the Customizer's Additional CSS (wp_head priority 101): between two
// !important rules the later one wins, so a smooth-scroll rule there would
// otherwise bring the animation back.
add_action( 'wp_head', 'petit_form_disable_status_scroll_animation', 1000 );

// Submission endpoint (logged-in and anonymous visitors).
add_action( 'admin_post_petit_form_submit', 'petit_form_handle_submit' );
add_action( 'admin_post_nopriv_petit_form_submit', 'petit_form_handle_submit' );

// Admin screens and actions.
if ( is_admin() ) {
	add_action( 'admin_menu', 'petit_form_admin_menu' );
	add_action( 'admin_init', 'petit_form_register_settings' );
	add_action( 'admin_post_petit_form_export_csv', 'petit_form_export_csv' );
	add_action( 'admin_post_petit_form_delete_lead', 'petit_form_delete_lead_action' );
	add_action( 'admin_notices', 'petit_form_admin_notices' );
}

/**
 * Front-end assets. Called directly from the shortcode: WordPress prints
 * late-enqueued styles in the footer, so assets only ever load on pages
 * that actually render a form — no flags, no transients, no guessing.
 */
function petit_form_enqueue_assets() {
	wp_enqueue_style(
		'petit-form',
		PETIT_FORM_URL . 'assets/petit-form.css',
		array(),
		PETIT_FORM_VERSION
	);
	wp_enqueue_script(
		'petit-form',
		PETIT_FORM_URL . 'assets/petit-form.js',
		array(),
		PETIT_FORM_VERSION,
		true
	);
	if ( petit_form_turnstile_enabled() ) {
		wp_enqueue_script(
			'cloudflare-turnstile',
			'https://challenges.cloudflare.com/turnstile/v0/api.js',
			array(),
			null,
			array( 'strategy' => 'defer' )
		);
	}
}
