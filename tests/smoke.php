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
	// Mimic wp_strip_all_tags: <script>/<style> blocks go away WITH content.
	$str = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $str );
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

function wp_create_nonce( $action = -1 ) {
	return substr( hash_hmac( 'sha256', (string) $action, wp_salt( 'nonce' ) ), 0, 10 );
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
ok( array() === petit_form_parse_fields( 'prénom, prenom' ), 'duplicate normalized keys reject the definition' );
ok( array() === petit_form_parse_fields( '!!!:required' ), 'empty normalized key rejects the definition' );

echo "placeholders parsing\n";

$placeholders = petit_form_parse_placeholders( 'prénom=Ton prénom|email=tonadresse@example.com|message=Comment puis-je t’aider ?' );
ok( 'Ton prénom' === $placeholders['prenom'], 'placeholder key normalization matches fields' );
ok( 'tonadresse@example.com' === $placeholders['email'], 'email placeholder is preserved' );
ok( 'Comment puis-je t’aider ?' === $placeholders['message'], 'Unicode placeholder is preserved' );
ok( array() === petit_form_parse_placeholders( 'email=sans séparateur|cassé' ), 'malformed map is rejected' );
ok( array() === petit_form_parse_placeholders( 'prénom=Un|prenom=Deux' ), 'duplicate normalized keys reject the map' );
ok( 255 === mb_strlen( petit_form_parse_placeholders( 'message=' . str_repeat( 'a', 300 ) )['message'] ), 'placeholder length is capped' );

echo "sanitization\n";

ok( '' === petit_form_sanitize_value( '<script>alert(1)</script>', 'text' ), 'text: script block removed with content (like wp_strip_all_tags)' );
ok( "a\nb" === petit_form_sanitize_value( "a\nb", 'tel' ), 'tel: invalid input preserved for validation' );
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
// security.php (traps; SQL quota and enabled Turnstile need integration tests)
// ---------------------------------------------------------------------------

echo "honeypot\n";

$spec = 'name:required, email:required';
$ts   = time() - 30;
$sig  = petit_form_time_trap_sign( $ts, 'contact', $spec );
$post = array( 'pf_hp_x91' => '', 'pf_ts' => (string) $ts, 'pf_sig' => $sig, 'pf_fields' => $spec );
ok( true === petit_form_verify_traps( $post, 'contact' ), 'empty honeypot + valid trap passes' );

$err = petit_form_verify_traps( array_merge( $post, array( 'pf_hp_x91' => 'http://spam.example' ) ), 'contact' );
ok( is_wp_error( $err ) && 'PF-E2002' === $err->get_error_code(), 'filled honeypot -> PF-E2002' );

echo "time-trap\n";

$fast_ts  = time();
$fast_sig = petit_form_time_trap_sign( $fast_ts, 'contact', $spec );
$err      = petit_form_verify_traps( array( 'pf_hp_x91' => '', 'pf_ts' => (string) $fast_ts, 'pf_sig' => $fast_sig, 'pf_fields' => $spec ), 'contact' );
ok( is_wp_error( $err ) && 'PF-E2003' === $err->get_error_code(), 'submitted in <3s -> PF-E2003' );

$err = petit_form_verify_traps( array_merge( $post, array( 'pf_sig' => 'forged' ) ), 'contact' );
ok( is_wp_error( $err ) && 'PF-E2004' === $err->get_error_code(), 'forged signature -> PF-E2004' );

$old_ts  = time() - 25 * 3600;
$old_sig = petit_form_time_trap_sign( $old_ts, 'contact', $spec );
$err     = petit_form_verify_traps( array( 'pf_hp_x91' => '', 'pf_ts' => (string) $old_ts, 'pf_sig' => $old_sig, 'pf_fields' => $spec ), 'contact' );
ok( is_wp_error( $err ) && 'PF-E2004' === $err->get_error_code(), 'stale form (>24h) -> PF-E2004' );

$cache_ts  = time() - 3 * 3600;
$cache_sig = petit_form_time_trap_sign( $cache_ts, 'contact', $spec );
ok( true === petit_form_verify_traps( array( 'pf_hp_x91' => '', 'pf_ts' => (string) $cache_ts, 'pf_sig' => $cache_sig, 'pf_fields' => $spec ), 'contact' ), 'page-cache scenario: 3h-old form still valid' );

echo "token refresh\n";

$definition_proof = petit_form_definition_sign( 'contact', $spec );
$fresh = petit_form_refresh_tokens( array( 'pf_form_id' => 'contact', 'pf_fields' => $spec, 'pf_definition_sig' => $definition_proof ) );
ok( is_array( $fresh ) && $fresh['nonce'] === wp_create_nonce( 'petit_form_submit_contact' ), 'valid definition proof issues a fresh nonce' );
ok( true === petit_form_verify_traps( array( 'pf_hp_x91' => '', 'pf_ts' => $fresh['timestamp'], 'pf_sig' => $fresh['signature'], 'pf_fields' => $spec ), 'contact' ), 'fresh signature matches the rendered definition' );
$err = petit_form_refresh_tokens( array( 'pf_form_id' => 'contact', 'pf_fields' => 'email', 'pf_definition_sig' => $definition_proof ) );
ok( is_wp_error( $err ) && 'PF-E2004' === $err->get_error_code(), 'definition proof blocks a modified spec' );
$err = petit_form_refresh_tokens( array( 'pf_form_id' => 'newsletter', 'pf_fields' => $spec, 'pf_definition_sig' => $definition_proof ) );
ok( is_wp_error( $err ) && 'PF-E2004' === $err->get_error_code(), 'definition proof is bound to its form ID' );

echo "fields spec tampering\n";

$err = petit_form_verify_traps( array_merge( $post, array( 'pf_fields' => 'email' ) ), 'contact' );
ok( is_wp_error( $err ) && 'PF-E2004' === $err->get_error_code(), 'spec stripped of required -> PF-E2004' );
$err = petit_form_verify_traps( array_merge( $post, array( 'pf_fields' => '' ) ), 'contact' );
ok( is_wp_error( $err ) && 'PF-E2004' === $err->get_error_code(), 'empty spec -> PF-E2004' );

echo "magic quotes regression (apostrophe in spec)\n";

// WordPress slash $_POST. A spec with an apostrophe ("J'accepte…") must
// still verify once the handler has unslashed it.
$fr_spec = "rgpd:checkbox:required:J'accepte la politique";
$fr_ts   = time() - 60;
$fr_sig  = petit_form_time_trap_sign( $fr_ts, 'guide', $fr_spec );
$slashed = array_map( 'addslashes', array( 'pf_hp_x91' => '', 'pf_ts' => (string) $fr_ts, 'pf_sig' => $fr_sig, 'pf_fields' => $fr_spec ) );
// Simulate the handler's unslash step:
$unslashed = array_map( 'stripslashes', $slashed );
ok( true === petit_form_verify_traps( $unslashed, 'guide' ), 'apostrophe spec verifies after unslash' );
$err = petit_form_verify_traps( $slashed, 'guide' );
ok( is_wp_error( $err ) && 'PF-E2004' === $err->get_error_code(), 'slashed spec is rejected (documents why unslash is mandatory)' );

// SQL quota behavior is covered by tests/integration.php with real WordPress.

echo "turnstile disabled by default\n";
ok( true === petit_form_verify_turnstile( array() ), 'no keys configured -> passes through' );

// ---------------------------------------------------------------------------
// mail.php + admin.php pure helpers
// ---------------------------------------------------------------------------

require_once dirname( __DIR__ ) . '/includes/mail.php';
require_once dirname( __DIR__ ) . '/includes/admin.php';

echo "mail header hygiene\n";
ok( 'Jean Dupont' === petit_form_strip_crlf( "Jean\nDupont" ), 'CR/LF stripped from name' );
ok( 'Jean Dupont' === petit_form_strip_crlf( 'Jean,Dupont' ), 'comma stripped (Reply-To split protection)' );
ok( 'jean' === petit_form_strip_crlf( '<jean>' ), 'angle brackets stripped' );

echo "csv row safety\n";
function pf_csv_capture( $row ) {
	$out = fopen( 'php://memory', 'w' );
	petit_form_csv_row( $out, $row );
	rewind( $out );
	$csv = stream_get_contents( $out );
	fclose( $out );
	return $csv;
}
ok( false !== strpos( pf_csv_capture( array( '=CMD|/C calc' ) ), "'=CMD" ), 'formula cell prefixed with quote' );
ok( "normal\n" === pf_csv_capture( array( 'normal' ) ), 'normal cell untouched' );

// ---------------------------------------------------------------------------
// Every PF-Exxxx code used in includes/ must be documented; visitor-facing
// codes must also exist in the message map.
// ---------------------------------------------------------------------------

echo "error code consistency\n";

$codes = array();
foreach ( glob( dirname( __DIR__ ) . '/includes/*.php' ) as $file ) {
	preg_match_all( '/\b(PF-E\d{4})\b/', file_get_contents( $file ), $m );
	$codes = array_merge( $codes, $m[1] );
}
$codes   = array_unique( $codes );
$readme  = file_get_contents( dirname( __DIR__ ) . '/README.md' );
$render  = file_get_contents( dirname( __DIR__ ) . '/includes/render.php' );
foreach ( $codes as $code ) {
	ok( false !== strpos( $readme, $code ), "$code documented in README.md" );
}
// Visitor-facing: every E1xxx/E2xxx code must have a mapped message.
foreach ( $codes as $code ) {
	if ( preg_match( '/^PF-E[12]/', $code ) ) {
		ok( (bool) preg_match( "/'" . $code . "'\\s*=>/", $render ), "$code has a visitor message" );
	}
}

// ---------------------------------------------------------------------------

if ( $GLOBALS['pf_failures'] > 0 ) {
	echo "\n{$GLOBALS['pf_failures']} FAILURE(S)\n";
	exit( 1 );
}
echo "\nAll green.\n";
exit( 0 );
