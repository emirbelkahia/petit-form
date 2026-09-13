<?php
/**
 * Smoke tests for Petit Form's pure logic — no WordPress, no PHPUnit.
 * Run: php tests/smoke.php   (exit code 0 = all green)
 *
 * WordPress functions used by the tested code are stubbed below. When you
 * change includes/fields.php or the trap logic, run this before shipping.
 *
 * @package PetitForm
 */

// Fake WordPress root so the "defined( 'ABSPATH' )" guards let the includes load.
define( 'ABSPATH', '/tmp/petit-form-tests/' );
define( 'PETIT_FORM_TESTS', true );
define( 'HOUR_IN_SECONDS', 3600 );

error_reporting( E_ALL );

// ---------------------------------------------------------------------------
// Minimal WordPress stubs (only what the tested code paths call).
// ---------------------------------------------------------------------------

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() {
		return $this->code;
	}
	public function get_error_message() {
		return $this->message;
	}
	public function get_error_data() {
		return $this->data;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function sanitize_text_field( $str ) {
	$str = (string) $str;
	$str = strip_tags( $str );
	$str = preg_replace( '/[\r\n\t]+/', ' ', $str );
	$str = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $str );
	return trim( $str );
}

function sanitize_textarea_field( $str ) {
	$str = (string) $str;
	$str = strip_tags( $str );
	$str = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $str );
	return trim( $str );
}

function sanitize_email( $email ) {
	return trim( (string) $email );
}

function is_email( $email ) {
	return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL );
}

function wp_unslash( $value ) {
	return $value; // tests never pass slashed data
}

function apply_filters( $hook, $value ) {
	return $value; // no filters registered in tests
}

function wp_salt( $scheme = 'auth' ) {
	return 'test-salt-for-smoke-tests';
}

// In-memory transients for rate-limit tests.
$GLOBALS['pf_test_transients'] = array();
function get_transient( $key ) {
	return array_key_exists( $key, $GLOBALS['pf_test_transients'] ) ? $GLOBALS['pf_test_transients'][ $key ] : false;
}
function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['pf_test_transients'][ $key ] = $value;
	return true;
}

$GLOBALS['pf_test_options'] = array(
	'petit_form_rate_max'    => 3,
	'petit_form_rate_window' => 3600,
	'petit_form_min_seconds' => 3,
);
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
function remove_accents( $str ) {
	return iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $str );
}
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['pf_test_options'] ) ? $GLOBALS['pf_test_options'][ $key ] : $default;
}

$_SERVER['REMOTE_ADDR'] = '203.0.113.10';

require_once dirname( __DIR__ ) . '/includes/fields.php';
require_once dirname( __DIR__ ) . '/includes/security.php';

// ---------------------------------------------------------------------------
// Tiny assertion helper.
// ---------------------------------------------------------------------------

$GLOBALS['pf_failures'] = 0;
function ok( $condition, $label ) {
	if ( $condition ) {
		echo "  PASS  {$label}\n";
	} else {
		$GLOBALS['pf_failures']++;
		echo "  FAIL  {$label}\n";
	}
}

// ---------------------------------------------------------------------------
// fields.php
// ---------------------------------------------------------------------------

echo "fields parsing\n";

$fields = petit_form_parse_fields( 'prenom:required, email:required, telephone, rgpd:checkbox:required:J\'accepte' );
ok( 4 === count( $fields ), 'parses 4 fields' );
ok( 'name' === $fields[0]['type'] && $fields[0]['required'], 'prenom defaults to name + required' );
ok( 'email' === $fields[1]['type'], 'email defaults to email' );
ok( 'tel' === $fields[2]['type'] && ! $fields[2]['required'], 'telephone optional tel' );
ok( 'checkbox' === $fields[3]['type'] && "J'accepte" === $fields[3]['label'], 'rgpd checkbox with custom label' );

$fields = petit_form_parse_fields( '' );
ok( 0 === count( $fields ), 'empty spec yields no fields' );

$fields = petit_form_parse_fields( 'prénom:required' );
ok( 'prenom' === $fields[0]['key'], 'prénom key becomes prenom (accents removed)' );
ok( 'Prénom' === $fields[0]['label'], 'label keeps the accent' );
ok( 'name' === $fields[0]['type'], 'prenom maps to name type' );

echo "sanitization\n";

ok( 'alert(1)' === petit_form_sanitize_value( '<script>alert(1)</script>', 'text' ), 'text: tags stripped' );
ok( '' === petit_form_sanitize_value( "a\nb", 'tel' ), 'tel: letters stripped' );
ok( '+33 6 12 34 56 78' === petit_form_sanitize_value( '+33 6 12 34 56 78', 'tel' ), 'tel: valid kept' );
ok( "ligne1\nligne2" === petit_form_sanitize_value( "ligne1\nligne2", 'textarea' ), 'textarea: newlines kept' );
ok( '1' === petit_form_sanitize_value( 'on', 'checkbox' ), 'checkbox: truthy -> 1' );

echo "validation\n";

$email_field = array( 'key' => 'email', 'type' => 'email', 'required' => true, 'label' => 'Email' );
ok( true === petit_form_validate_value( $email_field, 'a@b.fr' ), 'valid email passes' );
$err = petit_form_validate_value( $email_field, 'pas-un-email' );
ok( is_wp_error( $err ) && 'PF-E1102' === $err->get_error_code(), 'invalid email -> PF-E1102' );
$err = petit_form_validate_value( $email_field, '' );
ok( is_wp_error( $err ) && 'PF-E1101' === $err->get_error_code(), 'empty required -> PF-E1101' );

$tel_field = array( 'key' => 'telephone', 'type' => 'tel', 'required' => false, 'label' => 'Téléphone' );
ok( true === petit_form_validate_value( $tel_field, '' ), 'optional empty tel passes' );
ok( true === petit_form_validate_value( $tel_field, '06 12 34 56 78' ), 'FR mobile passes' );
$err = petit_form_validate_value( $tel_field, 'appelez-moi' );
ok( is_wp_error( $err ) && 'PF-E1103' === $err->get_error_code(), 'garbage tel -> PF-E1103' );

$text_field = array( 'key' => 'sujet', 'type' => 'text', 'required' => false, 'label' => 'Sujet' );
$err        = petit_form_validate_value( $text_field, str_repeat( 'a', 256 ) );
ok( is_wp_error( $err ) && 'PF-E1104' === $err->get_error_code(), '256-char text -> PF-E1104' );
ok( true === petit_form_validate_value( $text_field, str_repeat( 'a', 255 ) ), '255-char text passes' );

// ---------------------------------------------------------------------------
// security.php (traps + rate limiting; Turnstile disabled in tests)
// ---------------------------------------------------------------------------

echo "honeypot\n";

$spec = 'name:required, email:required';
$ts   = time() - 30;
$sig  = petit_form_time_trap_sign( $ts, 'contact', $spec );
$post = array( 'pf_company_url' => '', 'pf_ts' => $ts, 'pf_sig' => $sig, 'pf_fields' => $spec );
ok( true === petit_form_verify_traps( $post, 'contact' ), 'empty honeypot + valid trap passes' );

$err = petit_form_verify_traps( array_merge( $post, array( 'pf_company_url' => 'http://spam.example' ) ), 'contact' );
ok( is_wp_error( $err ) && 'PF-E2002' === $err->get_error_code(), 'filled honeypot -> PF-E2002' );

echo "time-trap\n";

$fast_ts  = time();
$fast_sig = petit_form_time_trap_sign( $fast_ts, 'contact', $spec );
$err      = petit_form_verify_traps( array( 'pf_company_url' => '', 'pf_ts' => $fast_ts, 'pf_sig' => $fast_sig, 'pf_fields' => $spec ), 'contact' );
ok( is_wp_error( $err ) && 'PF-E2003' === $err->get_error_code(), 'submitted in <3s -> PF-E2003' );

$err = petit_form_verify_traps( array_merge( $post, array( 'pf_sig' => 'forged' ) ), 'contact' );
ok( is_wp_error( $err ) && 'PF-E2004' === $err->get_error_code(), 'forged signature -> PF-E2004' );

$old_ts  = time() - 25 * 3600;
$old_sig = petit_form_time_trap_sign( $old_ts, 'contact', $spec );
$err     = petit_form_verify_traps( array( 'pf_company_url' => '', 'pf_ts' => $old_ts, 'pf_sig' => $old_sig, 'pf_fields' => $spec ), 'contact' );
ok( is_wp_error( $err ) && 'PF-E2004' === $err->get_error_code(), 'stale form (>24h) -> PF-E2004' );

$cache_ts  = time() - 3 * 3600;
$cache_sig = petit_form_time_trap_sign( $cache_ts, 'contact', $spec );
ok( true === petit_form_verify_traps( array( 'pf_company_url' => '', 'pf_ts' => $cache_ts, 'pf_sig' => $cache_sig, 'pf_fields' => $spec ), 'contact' ), 'page-cache scenario: 3h-old form still valid' );

echo "fields spec tampering\n";

$err = petit_form_verify_traps( array_merge( $post, array( 'pf_fields' => 'email' ) ), 'contact' );
ok( is_wp_error( $err ) && 'PF-E2004' === $err->get_error_code(), 'spec stripped of required -> PF-E2004' );
$err = petit_form_verify_traps( array_merge( $post, array( 'pf_fields' => '' ) ), 'contact' );
ok( is_wp_error( $err ) && 'PF-E2004' === $err->get_error_code(), 'empty spec -> PF-E2004' );

echo "rate limiting\n";

$GLOBALS['pf_test_transients'] = array();
ok( true === petit_form_rate_limit_check( 'contact' ), '1st submission allowed' );
ok( true === petit_form_rate_limit_check( 'contact' ), '2nd allowed' );
ok( true === petit_form_rate_limit_check( 'contact' ), '3rd allowed (max=3)' );
$err = petit_form_rate_limit_check( 'contact' );
ok( is_wp_error( $err ) && 'PF-E2005' === $err->get_error_code(), '4th -> PF-E2005' );
ok( true === petit_form_rate_limit_check( 'autre-form' ), 'other form has its own bucket' );

echo "turnstile disabled by default\n";
ok( true === petit_form_verify_turnstile( array() ), 'no keys configured -> passes through' );

// ---------------------------------------------------------------------------

if ( $GLOBALS['pf_failures'] > 0 ) {
	echo "\n{$GLOBALS['pf_failures']} FAILURE(S)\n";
	exit( 1 );
}
echo "\nAll green.\n";
exit( 0 );
