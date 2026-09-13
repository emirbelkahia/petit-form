<?php
/** Test-only MU plugin: prevent all actual email and external HTTP delivery. */
add_filter( 'pre_wp_mail', function ( $pre, $atts ) {
	$GLOBALS['pf_mail_calls'] = ( $GLOBALS['pf_mail_calls'] ?? 0 ) + 1;
	$GLOBALS['pf_last_mail'] = $atts;
	if ( getenv( 'PF_TEST_DELIVERY_LOG' ) ) {
		file_put_contents( getenv( 'PF_TEST_DELIVERY_LOG' ), "mail\n", FILE_APPEND | LOCK_EX );
		usleep( 100000 );
	}
	return $GLOBALS['pf_mail_result'] ?? true;
}, 10, 2 );
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	$GLOBALS['pf_http_calls'] = ( $GLOBALS['pf_http_calls'] ?? 0 ) + 1;
	$GLOBALS['pf_last_http_args'] = $args;
	if ( isset( $GLOBALS['pf_http_result'] ) ) {
		return $GLOBALS['pf_http_result'];
	}
	return new WP_Error( 'test-isolation', 'External HTTP disabled in tests.' );
}, 10, 3 );
