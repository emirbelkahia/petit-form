<?php
/** Real WordPress/SQL regressions. Only runs inside the disposable test installation. */
$root = getenv( 'PF_TEST_ROOT' );
if ( PHP_SAPI !== 'cli' || ! $root || ! is_file( $root . '/wordpress/petit-form-test-environment' ) ) {
	exit( "Run through tests/run-integration.sh.\n" );
}
require $root . '/wordpress/wp-load.php';
if ( DB_NAME !== file_get_contents( ABSPATH . 'petit-form-test-environment' ) || 'local' !== wp_get_environment_type() ) {
	throw new RuntimeException( 'Not a disposable test database.' );
}
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
$_SERVER['REQUEST_URI'] = '/contact/';

// One probe request per process: the status code is readable only before any output.
if ( 'probe' === ( $argv[1] ?? '' ) ) {
	$request = json_decode( getenv( 'PF_TEST_PROBE' ), true );
	if ( null !== $request['key'] ) {
		define( 'PETIT_FORM_PROBE_KEY', $request['key'] );
	}
	$_SERVER['HTTP_X_PETIT_FORM_PROBE'] = wp_slash( $request['header'] );
	$_POST = wp_slash( $request['post'] );
	$hooks = 0;
	add_action( 'petit_form_lead_created', function () use ( &$hooks ) { ++$hooks; } );
	register_shutdown_function( function () use ( &$hooks, $root ) {
		file_put_contents( $root . '/probe-meta', json_encode( array( 'status' => http_response_code(), 'hooks' => $hooks ) ) );
	} );
	do_action( 'admin_post_nopriv_petit_form_probe' );
	exit( 3 );
}

if ( isset( $argv[1] ) ) {
	file_put_contents( $root . '/ready-' . getmypid(), '' );
	$deadline = microtime( true ) + 20;
	while ( ! is_file( $root . '/go' ) ) {
		if ( microtime( true ) > $deadline ) {
			exit( 2 );
		}
		usleep( 10000 );
	}
	if ( 'rate' === $argv[1] ) {
		echo true === petit_form_rate_limit_check( 'concurrent' ) ? 'allowed' : 'denied';
	} else {
		petit_form_deliver_pending();
		echo 'done';
	}
	exit;
}

$checks = 0;
function check( $condition, $message ) {
	global $checks;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	++$checks;
	echo "PASS $message\n";
}
function http_response( $code, $body = '' ) {
	return array( 'response' => array( 'code' => $code ), 'body' => $body, 'headers' => array() );
}
function workers( $mode, $count ) {
	global $root;
	foreach ( glob( $root . '/ready-*' ) as $file ) {
		unlink( $file );
	}
	if ( is_file( $root . '/go' ) ) {
		unlink( $root . '/go' );
	}
	$children = array();
	for ( $i = 0; $i < $count; ++$i ) {
		$process = proc_open( array( PHP_BINARY, __FILE__, $mode ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$children[] = array( $process, $pipes );
	}
	$deadline = microtime( true ) + 20;
	while ( count( glob( $root . '/ready-*' ) ) < $count && microtime( true ) < $deadline ) {
		usleep( 10000 );
	}
	touch( $root . '/go' );
	$output = array();
	foreach ( $children as list( $process, $pipes ) ) {
		$output[] = stream_get_contents( $pipes[1] );
		$errors = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		check( 0 === proc_close( $process ), 'Concurrent worker exited cleanly: ' . $errors );
	}
	return $output;
}
class TestRedirect extends RuntimeException {}
class TestDenied extends RuntimeException {}
add_filter( 'wp_redirect', function ( $url ) { throw new TestRedirect( $url ); } );
add_filter( 'wp_die_handler', function () {
	return function ( $message ) { throw new TestDenied( strip_tags( $message ) ); };
} );
function submission( $id, $changes = array() ) {
	$spec = 'name:required,email:required,message:textarea:required';
	$ts = (string) ( time() - 10 );
	return array_merge( array(
		'pf_form_id' => $id, 'pf_fields' => $spec, 'pf_ts' => $ts,
		'pf_sig' => petit_form_time_trap_sign( $ts, $id, $spec ),
		'pf_nonce' => wp_create_nonce( 'petit_form_submit_' . $id ),
		'pf_hp_x91' => '', 'pf_back' => '/contact/',
		'pf_f_name' => 'Audit', 'pf_f_email' => 'audit@example.invalid', 'pf_f_message' => "Hello, it's a test.",
	), $changes );
}
function probe( $key, $header, $changes = array() ) {
	global $root;
	if ( is_file( $root . '/probe-meta' ) ) {
		unlink( $root . '/probe-meta' );
	}
	putenv( 'PF_TEST_PROBE=' . json_encode( array( 'key' => $key, 'header' => $header, 'post' => submission( 'probe', $changes ) ) ) );
	$process = proc_open( array( PHP_BINARY, __FILE__, 'probe' ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
	$body = stream_get_contents( $pipes[1] );
	$errors = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	check( 0 === proc_close( $process ), 'Probe handler ended the request itself: ' . $errors );
	putenv( 'PF_TEST_PROBE' );
	$meta = json_decode( file_get_contents( $root . '/probe-meta' ), true );
	return array( $meta['status'], json_decode( $body, true ), $meta['hooks'] );
}
function submit( $id, $changes = array() ) {
	$_POST = wp_slash( submission( $id, $changes ) );
	try {
		do_action( 'admin_post_nopriv_petit_form_submit' );
	} catch ( TestRedirect $redirect ) {
		return $redirect->getMessage();
	}
	throw new RuntimeException( 'Submission did not redirect.' );
}

$table = petit_form_table();
// Upgrade an actual 0.2.1 schema without notifying historical leads again.
$wpdb->query( "DROP TABLE $table" );
$wpdb->query( "CREATE TABLE $table (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, form_id varchar(64) NOT NULL, data text NOT NULL, ip_hash varchar(64) NOT NULL DEFAULT '', created_at datetime NOT NULL)" );
$wpdb->insert( $table, array( 'form_id' => 'historical', 'data' => '{"name":"Existing lead"}', 'created_at' => '2026-01-01 00:00:00' ) );
update_option( 'petit_form_db_version', '0.2.1' );
petit_form_maybe_upgrade();
$old = $wpdb->get_row( "SELECT * FROM $table LIMIT 1" );
check( 'Existing lead' === json_decode( $old->data, true )['name'] && null === $old->notify_data, 'Schema upgrade preserves existing leads without queueing them' );
check( PETIT_FORM_DB_VERSION === get_option( 'petit_form_db_version' ), 'Schema version is marked only after migration' );
$wpdb->query( "ALTER TABLE $table DROP COLUMN notify_attempts" );
update_option( 'petit_form_db_version', '0.2.1' );
$deny_alter = function ( $query ) { return 0 === strpos( $query, 'ALTER TABLE' ) ? 'SELECT FROM' : $query; };
add_filter( 'query', $deny_alter );
petit_form_maybe_upgrade();
check( '0.2.1' === get_option( 'petit_form_db_version' ) && get_option( 'petit_form_storage_error' ), 'Failed schema upgrade leaves its version unchanged and a visible error' );
remove_filter( 'query', $deny_alter );
petit_form_maybe_upgrade();
check( PETIT_FORM_DB_VERSION === get_option( 'petit_form_db_version' ) && ! get_option( 'petit_form_storage_error' ), 'Schema upgrade recovers once database writes work' );

update_option( 'petit_form_min_seconds', 0 );

$_GET = array();
ob_start();
petit_form_disable_status_scroll_animation();
$scroll_style = ob_get_clean();
check( '' === $scroll_style, 'Normal pages keep the theme scroll behavior' );
$_GET = array( 'pf_status' => 'ok', 'pf_form' => 'newsletter' );
ob_start();
petit_form_disable_status_scroll_animation();
$scroll_style = ob_get_clean();
check( false !== strpos( $scroll_style, 'scroll-behavior:auto!important' ), 'Status redirect disables the page-wide smooth-scroll animation' );
$_GET = array( 'pf_status' => 'forged', 'pf_form' => 'newsletter' );
ob_start();
petit_form_disable_status_scroll_animation();
$scroll_style = ob_get_clean();
check( '' === $scroll_style, 'Unknown status cannot inject the scroll override' );
$_GET = array( 'pf_status' => array( 'ok' ), 'pf_form' => array( 'newsletter' ) );
ob_start();
petit_form_disable_status_scroll_animation();
$scroll_style = ob_get_clean();
check( '' === $scroll_style, 'Array query parameters cannot inject the scroll override' );
$_GET = array( 'pf_status' => 'error', 'pf_form' => 'newsletter', 'pf_error' => 'PF-E1101' );
ob_start();
petit_form_disable_status_scroll_animation();
$scroll_style = ob_get_clean();
check( false !== strpos( $scroll_style, 'scroll-behavior:auto!important' ), 'Error responses also disable the smooth-scroll animation' );
add_filter( 'petit_form_disable_status_scroll', '__return_false' );
ob_start();
petit_form_disable_status_scroll_animation();
$scroll_style = ob_get_clean();
remove_filter( 'petit_form_disable_status_scroll', '__return_false' );
check( '' === $scroll_style, 'The petit_form_disable_status_scroll filter keeps the theme scroll behavior' );
check( has_action( 'wp_head', 'petit_form_disable_status_scroll_animation' ) > 101, 'The scroll override prints after the Customizer Additional CSS' );
$_GET = array();

$validation_html = petit_form_shortcode( array( 'id' => 'validation', 'fields' => 'email:required,consent:checkbox:required:I agree' ) );
check( false !== strpos( $validation_html, 'data-pf-checkbox-message=' ), 'Form exposes a localized checkbox validation message' );
check( false !== strpos( $validation_html, 'data-pf-email-message=' ), 'Form exposes a localized email validation message' );
check( wp_script_is( 'petit-form', 'enqueued' ), 'Client validation helper is enqueued only when a form is rendered' );

$multi_form_html = petit_form_shortcode( array( 'id' => 'contact' ) ) . petit_form_shortcode( array( 'id' => 'newsletter' ) );
$multi_form_dom  = new DOMDocument();
$previous        = libxml_use_internal_errors( true );
$multi_form_dom->loadHTML( '<?xml encoding="UTF-8">' . $multi_form_html );
libxml_clear_errors();
libxml_use_internal_errors( $previous );
$nonce_ids    = array();
$nonce_values = array();
foreach ( $multi_form_dom->getElementsByTagName( 'input' ) as $input ) {
	if ( 'pf_nonce' !== $input->getAttribute( 'name' ) ) {
		continue;
	}
	$nonce_ids[]    = $input->getAttribute( 'id' );
	$nonce_values[] = $input->getAttribute( 'value' );
}
check( array( 'pf-contact-nonce', 'pf-newsletter-nonce' ) === $nonce_ids, 'Two forms render distinct deterministic nonce element IDs' );
check( 2 === count( array_unique( $nonce_ids ) ), 'A page with two forms has no duplicate nonce ID' );
check( 1 === wp_verify_nonce( $nonce_values[0], 'petit_form_submit_contact' ), 'Contact nonce keeps its form-specific action' );
check( 1 === wp_verify_nonce( $nonce_values[1], 'petit_form_submit_newsletter' ), 'Newsletter nonce keeps its form-specific action' );
check( 2 === substr_count( $multi_form_html, 'name="pf_definition_sig"' ), 'Each rendered form carries a definition proof for token refresh' );
check( false !== has_action( 'template_redirect', 'petit_form_mark_status_response_uncacheable' ), 'Status responses register an early no-cache recovery path' );

$refresh_spec  = 'name:required,email:required,message:textarea:required';
$refresh_proof = petit_form_definition_sign( 'contact', $refresh_spec );
$fresh_tokens  = petit_form_refresh_tokens(
	array(
		'pf_form_id'        => 'contact',
		'pf_fields'         => $refresh_spec,
		'pf_definition_sig' => $refresh_proof,
	)
);
check( is_array( $fresh_tokens ) && 1 === wp_verify_nonce( $fresh_tokens['nonce'], 'petit_form_submit_contact' ), 'Refresh issues a valid form-specific nonce' );
check( true === petit_form_verify_traps( array( 'pf_hp_x91' => '', 'pf_ts' => $fresh_tokens['timestamp'], 'pf_sig' => $fresh_tokens['signature'], 'pf_fields' => $refresh_spec ), 'contact' ), 'Refresh issues a matching time-trap signature' );
$forged_refresh = petit_form_refresh_tokens(
	array(
		'pf_form_id'        => 'contact',
		'pf_fields'         => 'email',
		'pf_definition_sig' => $refresh_proof,
	)
);
check( is_wp_error( $forged_refresh ) && 'PF-E2004' === $forged_refresh->get_error_code(), 'Refresh refuses a modified field definition' );
$moved_refresh = petit_form_refresh_tokens(
	array(
		'pf_form_id'        => 'newsletter',
		'pf_fields'         => $refresh_spec,
		'pf_definition_sig' => $refresh_proof,
	)
);
check( is_wp_error( $moved_refresh ) && 'PF-E2004' === $moved_refresh->get_error_code(), 'Refresh refuses a definition proof moved to another form' );

$placeholder_html = petit_form_shortcode(
	array(
		'id'           => 'placeholder-test',
		'fields'       => 'prenom:required,email:required,message:textarea,consent:checkbox:I agree',
		'placeholders' => 'prenom=Ton prénom|email=alice@example.com|message=Comment puis-je t’aider ?|consent=ignored',
	)
);
check( false !== strpos( $placeholder_html, 'placeholder="Ton prénom"' ), 'Text input renders its configured placeholder' );
check( false !== strpos( $placeholder_html, 'placeholder="alice@example.com"' ), 'Email input renders its configured placeholder' );
check( false !== strpos( $placeholder_html, 'placeholder="Comment puis-je t’aider ?"' ), 'Textarea renders its configured placeholder' );
check( 3 === substr_count( $placeholder_html, ' placeholder=' ), 'Checkbox ignores its configured placeholder' );

foreach ( array( "J'accepte", 'Terms &amp; conditions', 'J&#039;accepte', 'A &quot;quote&quot;', 'Literal &amp;amp; text' ) as $label ) {
	$spec = 'consent:checkbox:required:' . $label;
	$html = petit_form_shortcode( array( 'id' => 'entities', 'fields' => $spec ) );
	$dom = new DOMDocument();
	$previous = libxml_use_internal_errors( true );
	$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
	libxml_clear_errors();
	libxml_use_internal_errors( $previous );
	$post = array();
	foreach ( $dom->getElementsByTagName( 'input' ) as $input ) {
		$post[ $input->getAttribute( 'name' ) ] = $input->getAttribute( 'value' );
	}
	check( $post['pf_fields'] === $spec && true === petit_form_verify_traps( wp_unslash( wp_slash( $post ) ), 'entities' ), 'HTML preserves signed bytes: ' . $label );
}
foreach ( array( 'alice@exämple.com', 'alice@exam!ple.com', "alice\nbob@example.com" ) as $raw ) {
	$value = petit_form_sanitize_value( $raw, 'email' );
	check( $value === $raw && is_wp_error( petit_form_validate_value( array( 'key' => 'email', 'type' => 'email', 'required' => true ), $value ) ), 'Invalid email rejected without rewriting: ' . str_replace( "\n", '\\n', $raw ) );
}
foreach ( array( '---...', 'call me', "06\n12345678" ) as $raw ) {
	check( is_wp_error( petit_form_validate_value( array( 'key' => 'phone', 'type' => 'tel', 'required' => false ), petit_form_sanitize_value( $raw, 'tel' ) ) ), 'Invalid phone rejected after normalization' );
}
check( array() === petit_form_parse_fields( 'prénom, prenom' ), 'Normalized duplicate fields rejected with real WordPress' );

// Database counters are shared by processes, independent of the WP object cache.
update_option( 'petit_form_rate_window', DAY_IN_SECONDS );
update_option( 'petit_form_rate_max', 10 );
for ( $i = 0; $i < 9; ++$i ) {
	check( true === petit_form_rate_limit_check( 'concurrent' ), 'Seed a quota slot' );
}
$key = $wpdb->get_var( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'petit_form_rate_%' LIMIT 1" );
$allowed = array_count_values( workers( 'rate', 12 ) );
check( 1 === ( $allowed['allowed'] ?? 0 ), 'Only one of 12 simultaneous requests consumes the last quota slot' );
check( '10' === $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) ), 'Concurrent quota counter stops at ten' );
check( true === petit_form_rate_limit_check( 'other-form' ), 'Another form has its own quota' );
$keys = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'petit_form_rate_%' ORDER BY option_name" );
petit_form_rate_limit_check( 'other-form' );
check( $keys === $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'petit_form_rate_%' ORDER BY option_name" ), 'A later admission preserves the fixed-window expiry key' );
$expired = 'petit_form_rate_' . sprintf( '%010d', time() - 1 ) . '_expired';
$wpdb->insert( $wpdb->options, array( 'option_name' => $expired, 'option_value' => '10', 'autoload' => 'no' ) );
petit_form_rate_limit_check( 'other-form' );
check( null === $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $expired ) ), 'Expired counters are removed' );
$deny_rate_update = function ( $query ) { return false !== strpos( $query, 'SET option_value = CAST(option_value AS UNSIGNED)' ) ? 'SELECT FROM' : $query; };
add_filter( 'query', $deny_rate_update );
$rate_error = petit_form_rate_limit_check( 'broken-db' );
check( is_wp_error( $rate_error ) && 'PF-E2009' === $rate_error->get_error_code(), 'A failed atomic counter update rejects the attempt' );
remove_filter( 'query', $deny_rate_update );

update_option( 'petit_form_rate_max', 1 );
update_option( 'petit_form_turnstile_site_key', 'test-site' );
update_option( 'petit_form_turnstile_secret_key', 'test-secret' );
$GLOBALS['pf_http_calls'] = 0;
$GLOBALS['pf_http_result'] = http_response( 200, '{"success":false}' );
check( false !== strpos( submit( 'captcha', array( 'cf-turnstile-response' => 'invalid' ) ), 'PF-E2007' ), 'Invalid CAPTCHA rejected' );
check( false !== strpos( submit( 'captcha', array( 'cf-turnstile-response' => 'invalid' ) ), 'PF-E2005' ) && 1 === $GLOBALS['pf_http_calls'], 'Exhausted quota blocks further CAPTCHA calls' );
check( false !== strpos( submit( 'typo', array( 'pf_f_email' => 'invalid' ) ), 'PF-E1102' ) && true === petit_form_rate_limit_check( 'typo' ), 'A local typo does not consume a quota slot' );
foreach ( array( 400 => 'PF-E2007', 500 => 'pf_status=ok' ) as $code => $expected ) {
	$GLOBALS['pf_http_result'] = http_response( $code );
	check( false !== strpos( submit( 'http-' . $code, array( 'cf-turnstile-response' => 'test' ) ), $expected ), 'Turnstile HTTP ' . $code . ' has the documented policy' );
}
$GLOBALS['pf_http_result'] = new WP_Error( 'test-outage', 'Network unavailable' );
check( false !== strpos( submit( 'outage', array( 'cf-turnstile-response' => 'test' ) ), 'pf_status=ok' ), 'Turnstile network failures fail open within quota' );
update_option( 'petit_form_turnstile_site_key', '' );
update_option( 'petit_form_rate_max', 10 );
check( false !== strpos( submit( 'tamper', array( 'pf_fields' => 'email' ) ), 'PF-E2004' ), 'Field-spec tampering remains rejected' );
check( false !== strpos( submit( 'nonce', array( 'pf_nonce' => 'invalid' ) ), 'PF-E2001' ), 'Invalid nonce rejected' );
check( false === strpos( submit( 'redirect', array( 'pf_back' => 'https://attacker.invalid/' ) ), 'attacker.invalid' ), 'External return URL rejected' );
$expired_spec = 'name:required,email:required,message:textarea:required';
$expired_ts   = (string) ( time() - DAY_IN_SECONDS - 60 );
check(
	false !== strpos(
		submit(
		'expired',
		array(
			'pf_ts'  => $expired_ts,
			'pf_sig' => petit_form_time_trap_sign( $expired_ts, 'expired', $expired_spec ),
		)
	),
	'PF-E2004'
	),
	'A form left open for more than 24 hours is rejected before storage'
);
$renewed = petit_form_refresh_tokens(
	array(
		'pf_form_id'        => 'expired',
		'pf_fields'         => $expired_spec,
		'pf_definition_sig' => petit_form_definition_sign( 'expired', $expired_spec ),
	)
);
check(
	false !== strpos(
		submit(
		'expired',
		array(
			'pf_nonce' => $renewed['nonce'],
			'pf_ts'    => $renewed['timestamp'],
			'pf_sig'   => $renewed['signature'],
		)
	),
	'pf_status=ok'
	),
	'The same old form submits successfully after token refresh'
);

// The monitoring probe replays the checks without storing, notifying or consuming quota.
$lead_count = "SELECT COUNT(*) FROM $table";
$quota_rows = "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'petit_form_rate_%' ORDER BY option_name";
$leads = $wpdb->get_var( $lead_count );
$quota = $wpdb->get_results( $quota_rows );
list( $status ) = probe( null, 'probe-secret' );
check( 404 === $status && $leads === $wpdb->get_var( $lead_count ), 'Probe does not exist without PETIT_FORM_PROBE_KEY' );
list( $status ) = probe( '', '' );
check( 404 === $status && $leads === $wpdb->get_var( $lead_count ), 'Probe does not exist with an empty PETIT_FORM_PROBE_KEY' );
list( $status, $body ) = probe( 'probe-secret', 'wrong-secret' );
check( 403 === $status && array( 'success' => false ) === $body && $leads === $wpdb->get_var( $lead_count ), 'Probe rejects a wrong key' );
update_option( 'petit_form_turnstile_site_key', 'test-site' );
list( $status, $body, $hooks ) = probe( 'probe-secret', 'probe-secret' );
update_option( 'petit_form_turnstile_site_key', '' );
check( 200 === $status && array( 'success' => true, 'data' => array( 'ok' => true, 'turnstile' => 'skipped' ) ) === $body, 'Valid probe answers 200 and reports Turnstile as skipped' );
check( $leads === $wpdb->get_var( $lead_count ) && 0 === $hooks, 'Valid probe stores no lead and does not fire petit_form_lead_created' );
check( $quota == $wpdb->get_results( $quota_rows ), 'Valid probe consumes no attempt' );
list( $status, $body ) = probe( 'probe-secret', 'probe-secret', array( 'pf_nonce' => 'invalid' ) );
check( 400 === $status && 'PF-E2001' === $body['data']['code'] && $leads === $wpdb->get_var( $lead_count ), 'Probe reports an invalid nonce as PF-E2001' );
list( $status, $body ) = probe( 'probe-secret', 'probe-secret', array( 'pf_fields' => 'email' ) );
check( 400 === $status && 'PF-E2004' === $body['data']['code'] && $leads === $wpdb->get_var( $lead_count ), 'Probe reports a modified field definition as PF-E2004' );
list( $status, $body ) = probe( 'probe-secret', 'probe-secret', array( 'pf_f_email' => 'invalid' ) );
check( 400 === $status && 'PF-E1102' === $body['data']['code'] && $leads === $wpdb->get_var( $lead_count ), 'Probe runs field validation' );

// Persist the queue atomically with the lead; no transport in the submit handler.
$wpdb->query( "TRUNCATE TABLE $table" );
update_option( 'petit_form_webhook_url', 'https://webhook.invalid/' );
$GLOBALS['pf_mail_calls'] = 0;
$GLOBALS['pf_http_calls'] = 0;
$GLOBALS['pf_http_result'] = http_response( 503 );
check( false !== strpos( submit( 'delivery' ), 'pf_status=ok' ) && 0 === $GLOBALS['pf_mail_calls'] && 0 === $GLOBALS['pf_http_calls'], 'Submission stores a lead without calling email or webhook' );
$lead = $wpdb->get_row( "SELECT * FROM $table LIMIT 1" );
check( "Hello, it's a test." === json_decode( $lead->data, true )['message'] && ! empty( $lead->notify_data ), 'Slashed message and pending delivery are saved together' );
petit_form_deliver_pending();
$state = json_decode( $wpdb->get_var( "SELECT notify_data FROM $table WHERE id = $lead->id" ), true );
check( ! $state['pending']['email'] && $state['pending']['webhook'], 'Email success is persisted while webhook failure remains pending' );
check( in_array( 'Reply-To: Audit <audit@example.invalid>', $GLOBALS['pf_last_mail']['headers'], true ), 'Deferred email preserves the visitor Reply-To' );
for ( $i = 0; $i < 2; ++$i ) {
	$wpdb->update( $table, array( 'notify_after' => time() - 1 ), array( 'id' => $lead->id ) );
	petit_form_deliver_pending();
}
$stopped = $wpdb->get_row( "SELECT * FROM $table WHERE id = $lead->id" );
check( 1 === $GLOBALS['pf_mail_calls'] && 3 === $GLOBALS['pf_http_calls'] && null === $stopped->notify_after && '3' === $stopped->notify_attempts, 'Only the failed channel retries, and delivery stops after three attempts' );
petit_form_deliver_pending();
check( 3 === $GLOBALS['pf_http_calls'], 'Stopped delivery is not retried indefinitely' );
$GLOBALS['pf_http_result'] = http_response( 302 );
check( is_wp_error( petit_form_send_webhook( 'test', array(), 1 ) ) && 0 === $GLOBALS['pf_last_http_args']['redirection'], 'Webhook redirects are disabled and non-2xx is rejected' );
$GLOBALS['pf_http_result'] = http_response( 204 );
check( true === petit_form_send_webhook( 'test', array(), 1 ), 'Webhook accepts 204' );

// A second worker cannot deliver a lead already claimed by the first worker.
$wpdb->query( "TRUNCATE TABLE $table" );
update_option( 'petit_form_webhook_url', '' );
petit_form_store_lead( 'parallel-delivery', array( 'name' => 'Worker test' ) );
putenv( 'PF_TEST_DELIVERY_LOG=' . $root . '/delivery.log' );
workers( 'delivery', 2 );
putenv( 'PF_TEST_DELIVERY_LOG' );
check( "mail\n" === file_get_contents( $root . '/delivery.log' ), 'Overlapping workers send a notification once' );
check( null === $wpdb->get_var( "SELECT notify_data FROM $table LIMIT 1" ), 'Successful delivery clears pending metadata' );
$id = petit_form_store_lead( 'interrupted-delivery', array( 'name' => 'Recovery test' ) );
$wpdb->update( $table, array( 'notify_attempts' => 1, 'notify_after' => time() - 1 ), array( 'id' => $id ) );
$GLOBALS['pf_mail_calls'] = 0;
petit_form_deliver_pending();
check( 1 === $GLOBALS['pf_mail_calls'] && null === $wpdb->get_var( "SELECT notify_data FROM $table WHERE id = $id" ), 'An expired lease is retried after an interrupted worker' );
$id = petit_form_store_lead( 'deleted-pending', array( 'name' => 'Deleted test' ) );
petit_form_delete_lead( $id );
$GLOBALS['pf_mail_calls'] = 0;
petit_form_deliver_pending();
check( 0 === $GLOBALS['pf_mail_calls'], 'Deleting a queued lead prevents later delivery' );

// Permissions and escaped admin output still hold after the schema changes.
foreach ( array( 'petit_form_leads_page', 'petit_form_export_csv', 'petit_form_delete_lead_action' ) as $action ) {
	$denied = false;
	try { $action(); } catch ( TestDenied $error ) { $denied = true; }
	check( $denied, 'Guest denied: ' . $action );
}
wp_set_current_user( 1 );
$_GET = array( 'lead' => 1 );
foreach ( array( 'petit_form_export_csv', 'petit_form_delete_lead_action' ) as $action ) {
	$denied = false;
	try { $action(); } catch ( TestDenied $error ) { $denied = true; }
	check( $denied, 'Admin without nonce denied: ' . $action );
}
petit_form_store_lead( 'html', array( 'name' => '<img src=x onerror=alert(1)>' ) );
$_GET = array();
ob_start(); petit_form_leads_page(); $html = ob_get_clean();
check( false === strpos( $html, '<img src=x onerror=alert(1)>' ) && false !== strpos( $html, '&lt;img src=x onerror=alert(1)&gt;' ), 'Stored HTML is escaped in the admin list' );

$wpdb->query( "DROP TABLE $table" );
petit_form_maybe_upgrade();
check( petit_form_table_exists(), 'Admin visit repairs a missing table at the current schema version' );
$wpdb->query( "DROP TABLE $table" );
$id = petit_form_store_lead( 'repair', array( 'message' => 'Recovered insert' ) );
check( is_int( $id ) && 1 === petit_form_count_leads( 'repair' ), 'A failed insert repairs an absent table and retries once' );
$wpdb->query( "ALTER TABLE $table MODIFY data TEXT CHARACTER SET utf8mb3 NOT NULL" );
$id = petit_form_store_lead( 'unicode', array( 'message' => 'Emoji 📩' ) );
check( is_int( $id ) && 'Emoji 📩' === json_decode( $wpdb->get_var( "SELECT data FROM $table WHERE id = $id" ), true )['message'], 'Unicode JSON round-trips on a legacy three-byte database' );

petit_form_schedule_delivery();
check( false !== wp_next_scheduled( 'petit_form_deliver_pending' ), 'Delivery schedule exists' );
// Diagnose cron from its schedule, including sites using an external scheduler.
function cron_notice( $screen = 'index.php', $page = '' ) {
	global $pagenow;
	$pagenow = $screen;
	$_GET = '' === $page ? array() : array( 'page' => $page );
	ob_start();
	petit_form_admin_notices();
	return ob_get_clean();
}
check( DISABLE_WP_CRON && false === strpos( cron_notice(), 'PF-E4003' ), 'A healthy schedule with visit-triggered cron disabled produces no warning' );
wp_clear_scheduled_hook( 'petit_form_deliver_pending' );
wp_schedule_event( time() - 2 * MINUTE_IN_SECONDS, 'petit_form_minute', 'petit_form_deliver_pending' );
check( false === strpos( cron_notice(), 'PF-E4003' ), 'A short cron delay does not produce a warning' );
wp_clear_scheduled_hook( 'petit_form_deliver_pending' );
wp_schedule_event( time() - 16 * MINUTE_IN_SECONDS, 'petit_form_minute', 'petit_form_deliver_pending' );
$notice = cron_notice();
check( false !== strpos( $notice, 'PF-E4003' ) && false !== strpos( $notice, 'more than 15 minutes late' ) && false !== strpos( $notice, 'Ask your host' ), 'An overdue task shows an actionable administrator warning' );
check( false === strpos( cron_notice( 'admin.php', 'wpfastestcacheoptions' ), 'PF-E4003' ), 'Cron warning stays off unrelated plugin screens' );
check( false !== strpos( cron_notice( 'admin.php', 'petit-form-leads' ), 'PF-E4003' ), 'Cron warning shows on the leads screen' );
wp_set_current_user( 0 );
check( '' === cron_notice(), 'Cron diagnostics are hidden from visitors' );
wp_set_current_user( 1 );
wp_clear_scheduled_hook( 'petit_form_deliver_pending' );
add_filter( 'pre_schedule_event', '__return_false' );
petit_form_schedule_delivery();
$notice = cron_notice();
check( false !== strpos( $notice, 'PF-E4003' ) && false !== strpos( $notice, 'task is missing' ), 'Failed scheduling produces a visible warning without relying on logs' );
remove_filter( 'pre_schedule_event', '__return_false' );
petit_form_schedule_delivery();
check( false === strpos( cron_notice(), 'PF-E4003' ), 'Cron warning clears once scheduling recovers' );
petit_form_deactivate();
check( false === wp_next_scheduled( 'petit_form_deliver_pending' ), 'Deactivation removes the schedule' );
petit_form_schedule_delivery();
define( 'WP_UNINSTALL_PLUGIN', 'petit-form/petit-form.php' );
require dirname( __DIR__ ) . '/uninstall.php';
check( petit_form_table_exists() && false === wp_next_scheduled( 'petit_form_deliver_pending' ), 'Default uninstall keeps leads and removes delivery schedule' );
update_option( 'petit_form_delete_data_on_uninstall', true );
require dirname( __DIR__ ) . '/uninstall.php';
check( ! petit_form_table_exists() && false === get_option( 'petit_form_db_version' ), 'Opt-in uninstall removes the table and settings' );
echo "Integration: $checks checks passed.\n";
