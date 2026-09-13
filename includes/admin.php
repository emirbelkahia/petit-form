<?php
/**
 * Admin screens: "Leads" list (view, delete, CSV export) and settings.
 * Every state-changing action is nonce-protected and capability-checked —
 * the 2026 CF7 DB Handler CVE was exactly a missing nonce on a bulk action.
 *
 * @package PetitForm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the top-level Leads menu and the settings sub-page.
 */
function petit_form_admin_menu() {
	add_menu_page(
		__( 'Leads', 'petit-form' ),
		__( 'Leads', 'petit-form' ),
		'manage_options',
		'petit-form-leads',
		'petit_form_leads_page',
		'dashicons-email-alt',
		58
	);
	add_submenu_page(
		'petit-form-leads',
		__( 'Petit Form settings', 'petit-form' ),
		__( 'Settings', 'petit-form' ),
		'manage_options',
		'petit-form-settings',
		'petit_form_settings_page'
	);
}

/**
 * Register all settings with sanitize callbacks.
 */
function petit_form_register_settings() {
	$string = array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' );
	$int    = array( 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0 );

	register_setting( 'petit_form', 'petit_form_notify_email', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_email', 'default' => '' ) );
	register_setting( 'petit_form', 'petit_form_webhook_url', array( 'type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => '' ) );
	register_setting( 'petit_form', 'petit_form_webhook_header_name', $string );
	register_setting( 'petit_form', 'petit_form_webhook_header_value', $string );
	register_setting( 'petit_form', 'petit_form_turnstile_site_key', $string );
	register_setting( 'petit_form', 'petit_form_turnstile_secret_key', $string );
	register_setting( 'petit_form', 'petit_form_rate_max', array( 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 5 ) );
	register_setting( 'petit_form', 'petit_form_rate_window', array( 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => HOUR_IN_SECONDS ) );
	register_setting( 'petit_form', 'petit_form_min_seconds', array( 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 3 ) );
	register_setting( 'petit_form', 'petit_form_delete_data_on_uninstall', array( 'type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean', 'default' => false ) );
}

/**
 * Leads list page: filter by form, paginate, delete, export.
 */
function petit_form_leads_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Access denied.', 'petit-form' ) );
	}

	$filter  = isset( $_GET['pf_filter'] ) ? sanitize_key( wp_unslash( $_GET['pf_filter'] ) ) : '';
	$paged   = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
	$per     = 50;
	$total   = petit_form_count_leads( $filter );
	$leads   = petit_form_get_leads( $filter, $per, ( $paged - 1 ) * $per );
	$pages   = max( 1, (int) ceil( $total / $per ) );
	$forms   = petit_form_known_form_ids();

	$export_url = wp_nonce_url(
		admin_url( 'admin-post.php?action=petit_form_export_csv' . ( $filter ? '&pf_filter=' . $filter : '' ) ),
		'petit_form_export_csv'
	);
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Leads', 'petit-form' ); ?>
			<a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action"><?php esc_html_e( 'Export CSV', 'petit-form' ); ?></a>
		</h1>

		<form method="get" style="margin:12px 0;">
			<input type="hidden" name="page" value="petit-form-leads" />
			<select name="pf_filter">
				<option value=""><?php esc_html_e( 'All forms', 'petit-form' ); ?></option>
				<?php foreach ( $forms as $fid ) : ?>
					<option value="<?php echo esc_attr( $fid ); ?>" <?php selected( $filter, $fid ); ?>><?php echo esc_html( $fid ); ?></option>
				<?php endforeach; ?>
			</select>
			<button class="button"><?php esc_html_e( 'Filter', 'petit-form' ); ?></button>
			<span style="margin-left:8px;color:#646970;"><?php echo esc_html( sprintf( __( '%d leads', 'petit-form' ), $total ) ); ?></span>
		</form>

		<table class="widefat striped">
			<thead>
				<tr>
					<th style="width:60px;">#</th>
					<th style="width:160px;"><?php esc_html_e( 'Date (UTC)', 'petit-form' ); ?></th>
					<th style="width:140px;"><?php esc_html_e( 'Form', 'petit-form' ); ?></th>
					<th><?php esc_html_e( 'Data', 'petit-form' ); ?></th>
					<th style="width:80px;"></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $leads ) ) : ?>
				<tr><td colspan="5"><?php esc_html_e( 'No leads yet.', 'petit-form' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $leads as $lead ) : ?>
				<?php $data = json_decode( $lead->data, true ); ?>
				<tr>
					<td><?php echo (int) $lead->id; ?></td>
					<td><?php echo esc_html( $lead->created_at ); ?></td>
					<td><code><?php echo esc_html( $lead->form_id ); ?></code></td>
					<td>
						<?php foreach ( (array) $data as $k => $v ) : ?>
							<strong><?php echo esc_html( $k ); ?></strong> : <?php echo esc_html( $v ); ?><br />
						<?php endforeach; ?>
					</td>
					<td>
						<?php
						$del_url = wp_nonce_url(
							admin_url( 'admin-post.php?action=petit_form_delete_lead&lead=' . (int) $lead->id ),
							'petit_form_delete_lead_' . (int) $lead->id
						);
						?>
						<a href="<?php echo esc_url( $del_url ); ?>" class="button button-small" onclick="return confirm('<?php esc_attr_e( 'Delete this lead permanently?', 'petit-form' ); ?>');"><?php esc_html_e( 'Delete', 'petit-form' ); ?></a>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $pages > 1 ) : ?>
			<p class="tablenav">
				<?php for ( $i = 1; $i <= $pages; $i++ ) : ?>
					<?php if ( $i === $paged ) : ?>
						<strong><?php echo (int) $i; ?></strong>
					<?php else : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=petit-form-leads&paged=' . $i . ( $filter ? '&pf_filter=' . $filter : '' ) ) ); ?>"><?php echo (int) $i; ?></a>
					<?php endif; ?>
				<?php endfor; ?>
			</p>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Distinct form ids present in the leads table (for the filter select).
 *
 * @return array<int,string>
 */
function petit_form_known_form_ids() {
	global $wpdb;
	$table = petit_form_table();
	return array_map( 'strval', (array) $wpdb->get_col( "SELECT DISTINCT form_id FROM {$table} ORDER BY form_id" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

/**
 * CSV export. Capability + nonce checked, streams and exits.
 */
function petit_form_export_csv() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Access denied.', 'petit-form' ) );
	}
	if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'petit_form_export_csv' ) ) {
		wp_die( esc_html__( 'Invalid nonce (PF-E2001).', 'petit-form' ) );
	}
	$filter = isset( $_GET['pf_filter'] ) ? sanitize_key( wp_unslash( $_GET['pf_filter'] ) ) : '';
	$leads  = petit_form_get_all_leads_for_export( $filter );

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="petit-form-leads-' . gmdate( 'Y-m-d' ) . '.csv"' );

	$out = fopen( 'php://output', 'w' );
	fputcsv( $out, array( 'id', 'created_at_utc', 'form', 'data_json' ) );
	foreach ( $leads as $lead ) {
		fputcsv( $out, array( $lead->id, $lead->created_at, $lead->form_id, $lead->data ) );
	}
	fclose( $out );
	exit;
}

/**
 * Single-lead deletion. Capability + per-lead nonce checked.
 */
function petit_form_delete_lead_action() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Access denied.', 'petit-form' ) );
	}
	$lead_id = isset( $_GET['lead'] ) ? (int) $_GET['lead'] : 0;
	if ( ! $lead_id || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'petit_form_delete_lead_' . $lead_id ) ) {
		wp_die( esc_html__( 'Invalid nonce (PF-E2001).', 'petit-form' ) );
	}
	petit_form_delete_lead( $lead_id );
	set_transient( 'petit_form_notice', 'deleted', 30 );
	wp_safe_redirect( admin_url( 'admin.php?page=petit-form-leads' ) );
	exit;
}

/**
 * Settings page.
 */
function petit_form_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Petit Form settings', 'petit-form' ); ?></h1>
		<form method="post" action="options.php">
			<?php settings_fields( 'petit_form' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="petit_form_notify_email"><?php esc_html_e( 'Notification email', 'petit-form' ); ?></label></th>
					<td><input type="email" class="regular-text" id="petit_form_notify_email" name="petit_form_notify_email" value="<?php echo esc_attr( get_option( 'petit_form_notify_email', get_option( 'admin_email' ) ) ); ?>" />
					<p class="description"><?php esc_html_e( 'Leads are always stored in the database; this email is only a notification.', 'petit-form' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="petit_form_webhook_url"><?php esc_html_e( 'Webhook URL', 'petit-form' ); ?></label></th>
					<td><input type="url" class="regular-text" id="petit_form_webhook_url" name="petit_form_webhook_url" value="<?php echo esc_attr( get_option( 'petit_form_webhook_url', '' ) ); ?>" />
					<p class="description"><?php esc_html_e( 'Optional. Every new lead is POSTed there as JSON.', 'petit-form' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Webhook auth header', 'petit-form' ); ?></th>
					<td>
						<input type="text" class="regular-text" name="petit_form_webhook_header_name" placeholder="X-API-Key" value="<?php echo esc_attr( get_option( 'petit_form_webhook_header_name', '' ) ); ?>" />
						<input type="password" class="regular-text" name="petit_form_webhook_header_value" value="<?php echo esc_attr( get_option( 'petit_form_webhook_header_value', '' ) ); ?>" autocomplete="off" />
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Cloudflare Turnstile', 'petit-form' ); ?></th>
					<td>
						<input type="text" class="regular-text" name="petit_form_turnstile_site_key" placeholder="<?php esc_attr_e( 'Site key', 'petit-form' ); ?>" value="<?php echo esc_attr( get_option( 'petit_form_turnstile_site_key', '' ) ); ?>" />
						<input type="password" class="regular-text" name="petit_form_turnstile_secret_key" placeholder="<?php esc_attr_e( 'Secret key', 'petit-form' ); ?>" value="<?php echo esc_attr( get_option( 'petit_form_turnstile_secret_key', '' ) ); ?>" autocomplete="off" />
						<p class="description"><?php esc_html_e( 'Optional second anti-spam layer. Leave empty to stay on honeypot + time-trap + rate limiting.', 'petit-form' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Rate limiting', 'petit-form' ); ?></th>
					<td>
						<input type="number" min="1" max="100" name="petit_form_rate_max" value="<?php echo esc_attr( (string) get_option( 'petit_form_rate_max', 5 ) ); ?>" style="width:80px;" />
						<?php esc_html_e( 'submissions per', 'petit-form' ); ?>
						<input type="number" min="60" max="86400" name="petit_form_rate_window" value="<?php echo esc_attr( (string) get_option( 'petit_form_rate_window', HOUR_IN_SECONDS ) ); ?>" style="width:100px;" />
						<?php esc_html_e( 'seconds, per IP and per form.', 'petit-form' ); ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="petit_form_min_seconds"><?php esc_html_e( 'Minimum fill time', 'petit-form' ); ?></label></th>
					<td><input type="number" min="0" max="60" name="petit_form_min_seconds" id="petit_form_min_seconds" value="<?php echo esc_attr( (string) get_option( 'petit_form_min_seconds', 3 ) ); ?>" style="width:80px;" />
					<?php esc_html_e( 'seconds. Faster submissions are rejected as bots.', 'petit-form' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Data on uninstall', 'petit-form' ); ?></th>
					<td><label><input type="checkbox" name="petit_form_delete_data_on_uninstall" value="1" <?php checked( get_option( 'petit_form_delete_data_on_uninstall', false ) ); ?> />
					<?php esc_html_e( 'Delete the leads table and all settings when the plugin is uninstalled.', 'petit-form' ); ?></label></td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

/**
 * One-shot admin notices (lead deleted, etc.).
 */
function petit_form_admin_notices() {
	$notice = get_transient( 'petit_form_notice' );
	if ( 'deleted' === $notice ) {
		delete_transient( 'petit_form_notice' );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Lead deleted.', 'petit-form' ) . '</p></div>';
	}
}
