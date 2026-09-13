<?php
/**
 * Shortcode rendering. Outputs accessible, escaped HTML with all anti-bot
 * fields. No JavaScript required (Turnstile excepted when enabled).
 *
 * @package PetitForm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode handler.
 *
 * Examples:
 *   [petit-form id="contact" fields="name:required, email:required, message:textarea:required"]
 *   [petit-form id="guide" fields="prenom, email:required, telephone, rgpd:checkbox:required:J'accepte la politique de confidentialité" submit="Recevoir le guide"]
 *
 * Attributes:
 *   id      (required) form identifier, used in hooks, rate limiting, leads table
 *   fields  (required) comma-separated field spec, see petit_form_parse_fields()
 *   submit  (optional) submit button label
 *   success (optional) success message
 */
function petit_form_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'id'      => '',
			'fields'  => 'name:required, email:required, message:textarea:required',
			'submit'  => __( 'Send', 'petit-form' ),
			'success' => __( 'Thank you! Your message has been sent.', 'petit-form' ),
		),
		$atts,
		'petit-form'
	);

	$form_id = sanitize_key( $atts['id'] );
	if ( '' === $form_id ) {
		petit_form_log( 'PF-E1001', 'Shortcode missing the id attribute.' );
		return current_user_can( 'manage_options' )
			? '<p class="pf-error">[petit-form] ' . esc_html__( 'Missing required "id" attribute (PF-E1001).', 'petit-form' ) . '</p>'
			: '';
	}

	$fields = petit_form_parse_fields( $atts['fields'] );
	if ( empty( $fields ) ) {
		petit_form_log( 'PF-E1001', 'Shortcode has no usable fields: ' . $atts['fields'] );
		return current_user_can( 'manage_options' )
			? '<p class="pf-error">[petit-form] ' . esc_html__( 'No usable fields (PF-E1001).', 'petit-form' ) . '</p>'
			: '';
	}

	petit_form_enqueue_assets();

	// Status after redirect (success / error code + field errors).
	// pf_error is whitelisted to the PF-Exxxx shape: a forged link must not
	// display attacker-controlled text inside the form (phishing).
	$status   = isset( $_GET['pf_status'] ) ? sanitize_key( wp_unslash( $_GET['pf_status'] ) ) : '';
	$err_code = isset( $_GET['pf_error'] ) ? sanitize_text_field( wp_unslash( $_GET['pf_error'] ) ) : '';
	$err_code = preg_match( '/^PF-E\d{4}$/', $err_code ) ? $err_code : '';
	$err_for  = isset( $_GET['pf_form'] ) ? sanitize_key( wp_unslash( $_GET['pf_form'] ) ) : '';
	$is_ours  = ( $err_for === $form_id );

	ob_start();
	?>
	<form class="petit-form" id="pf-<?php echo esc_attr( $form_id ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php if ( $is_ours && 'ok' === $status ) : ?>
			<p class="pf-success" role="status"><?php echo esc_html( $atts['success'] ); ?></p>
		<?php endif; ?>
		<?php if ( $is_ours && 'error' === $status ) : ?>
			<p class="pf-error" role="alert">
				<?php echo esc_html( petit_form_user_message_for( $err_code ) ); ?>
				<code><?php echo esc_html( $err_code ); ?></code>
			</p>
		<?php endif; ?>

		<?php foreach ( $fields as $field ) : ?>
			<?php petit_form_render_field( $field, $form_id ); ?>
		<?php endforeach; ?>

		<?php petit_form_render_trap_fields( $form_id, $atts['fields'] ); ?>
		<input type="hidden" name="action" value="petit_form_submit" />
		<input type="hidden" name="pf_form_id" value="<?php echo esc_attr( $form_id ); ?>" />
		<input type="hidden" name="pf_fields" value="<?php echo esc_attr( $atts['fields'] ); ?>" />
		<input type="hidden" name="pf_back" value="<?php echo esc_url( petit_form_current_url() ); ?>" />
		<?php wp_nonce_field( 'petit_form_submit_' . $form_id, 'pf_nonce' ); ?>

		<?php if ( petit_form_turnstile_enabled() ) : ?>
			<div class="cf-turnstile" data-sitekey="<?php echo esc_attr( get_option( 'petit_form_turnstile_site_key', '' ) ); ?>"></div>
		<?php endif; ?>

		<button type="submit" class="pf-submit"><?php echo esc_html( $atts['submit'] ); ?></button>
	</form>
	<?php
	return ob_get_clean();
}

/**
 * Render a single field row (label + input), fully escaped. The element id
 * is prefixed with the form id so two forms on one page never collide.
 */
function petit_form_render_field( $field, $form_id = '' ) {
	$id       = 'pf-' . ( $form_id ? $form_id . '-' : '' ) . $field['key'];
	$required = $field['required'] ? ' required aria-required="true"' : '';
	?>
	<p class="pf-field pf-field-<?php echo esc_attr( $field['type'] ); ?>">
		<label for="<?php echo esc_attr( $id ); ?>">
			<?php echo esc_html( $field['label'] ); ?><?php echo $field['required'] ? ' <span class="pf-required" aria-hidden="true">*</span>' : ''; ?>
		</label>
		<?php if ( 'textarea' === $field['type'] ) : ?>
			<textarea id="<?php echo esc_attr( $id ); ?>" name="pf_f_<?php echo esc_attr( $field['key'] ); ?>" rows="5"<?php echo $required; // phpcs:ignore WordPress.Security.EscapeOutput ?>></textarea>
		<?php elseif ( 'checkbox' === $field['type'] ) : ?>
			<span class="pf-checkbox-row">
				<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="pf_f_<?php echo esc_attr( $field['key'] ); ?>" value="1"<?php echo $required; // phpcs:ignore WordPress.Security.EscapeOutput ?> />
			</span>
		<?php else : ?>
			<input type="<?php echo esc_attr( 'name' === $field['type'] ? 'text' : $field['type'] ); ?>" id="<?php echo esc_attr( $id ); ?>" name="pf_f_<?php echo esc_attr( $field['key'] ); ?>"<?php echo $required; // phpcs:ignore WordPress.Security.EscapeOutput ?> />
		<?php endif; ?>
	</p>
	<?php
}

/**
 * Current page path, for the post/redirect/get loop. Relative on purpose:
 * home_url() + REQUEST_URI would duplicate the path on subdirectory
 * installs, and wp_safe_redirect accepts relative paths.
 */
function petit_form_current_url() {
	return isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
}

/**
 * Map an error code to a friendly, non-technical visitor message.
 * The code itself is displayed alongside so a site owner can look it up.
 *
 * Messages can be overridden without touching this plugin:
 *   add_filter( 'petit_form_user_message', fn( $msg, $code ) =>
 *       'PF-E2005' === $code ? 'Doucement !' : $msg, 10, 2 );
 */
function petit_form_user_message_for( $code ) {
	$generic = __( 'Sorry, your message could not be sent. Please try again.', 'petit-form' );
	$map     = array(
		'PF-E1101' => __( 'A required field is missing.', 'petit-form' ),
		'PF-E1102' => __( 'The email address looks invalid.', 'petit-form' ),
		'PF-E1103' => __( 'The phone number looks invalid.', 'petit-form' ),
		'PF-E2003' => __( 'The form was submitted too quickly. Please try again.', 'petit-form' ),
		'PF-E2004' => __( 'The form has expired. Please reload the page and try again.', 'petit-form' ),
		'PF-E2005' => __( 'Too many attempts. Please try again later.', 'petit-form' ),
		'PF-E2006' => __( 'The anti-spam check is missing. Please reload the page.', 'petit-form' ),
		'PF-E2007' => __( 'The anti-spam check failed. Please try again.', 'petit-form' ),
	);
	$message = isset( $map[ $code ] ) ? $map[ $code ] : $generic;

	/**
	 * Filter any visitor-facing message by error code.
	 *
	 * @param string $message The default message.
	 * @param string $code    The stable error code (PF-Exxxx), '' on success.
	 */
	return apply_filters( 'petit_form_user_message', $message, $code );
}
