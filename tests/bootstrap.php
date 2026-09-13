<?php
/** Install/remove only the disposable database named by the test runner. */
if ( PHP_SAPI !== 'cli' || ! preg_match( '/^petit_form_test_[0-9]+_[0-9]+$/', (string) getenv( 'PF_TEST_DB' ) ) ) {
	exit( "Run through tests/run-integration.sh.\n" );
}
$root = getenv( 'PF_TEST_ROOT' );
$db   = getenv( 'PF_TEST_DB' );
$host = getenv( 'PF_DB_HOST' ) ?: '127.0.0.1';
$port = (int) ( getenv( 'PF_DB_PORT' ) ?: 3306 );
$user = getenv( 'PF_DB_USER' ) ?: 'root';
$pass = getenv( 'PF_DB_PASSWORD' ) ?: '';
$sql  = new mysqli( $host, $user, $pass, '', $port );
if ( 'cleanup' === ( $argv[1] ?? '' ) ) {
	$sql->query( "DROP DATABASE IF EXISTS `$db`" );
	exit;
}
$sql->query( "CREATE DATABASE `$db` CHARACTER SET utf8mb4" );
$zip = new ZipArchive();
if ( true !== $zip->open( $root . '/wordpress.zip' ) || ! $zip->extractTo( $root ) ) {
	throw new RuntimeException( 'Could not extract WordPress.' );
}
$zip->close();
$test_wp_path = $root . '/wordpress';
file_put_contents( $test_wp_path . '/petit-form-test-environment', $db );
$constants = array(
	'DB_NAME' => $db, 'DB_USER' => $user, 'DB_PASSWORD' => $pass,
	'DB_HOST' => $host . ':' . $port, 'DB_CHARSET' => 'utf8mb4', 'DB_COLLATE' => '',
	'WP_HOME' => 'http://petit-form.test', 'WP_SITEURL' => 'http://petit-form.test',
	'WP_ENVIRONMENT_TYPE' => 'local', 'WP_DEBUG' => true, 'WP_DEBUG_DISPLAY' => false,
	'WP_DEBUG_LOG' => true, 'DISABLE_WP_CRON' => true, 'AUTOMATIC_UPDATER_DISABLED' => true,
	'WP_DISABLE_FATAL_ERROR_HANDLER' => true,
);
$config = "<?php\n";
foreach ( $constants as $name => $value ) {
	$config .= 'define(' . var_export( $name, true ) . ', ' . var_export( $value, true ) . ");\n";
}
$config .= '$table_prefix = "wp_";' . "\nrequire_once __DIR__ . '/wp-settings.php';\n";
file_put_contents( $test_wp_path . '/wp-config.php', $config );
mkdir( $test_wp_path . '/wp-content/mu-plugins' );
copy( __DIR__ . '/isolation.php', $test_wp_path . '/wp-content/mu-plugins/isolation.php' );
// A symlink exercises the working tree, with no generated files in the repo.
symlink( dirname( __DIR__ ), $test_wp_path . '/wp-content/plugins/petit-form' );
define( 'WP_INSTALLING', true );
require $test_wp_path . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
wp_install( 'Petit Form tests', 'audit', 'audit@example.invalid', false, '', 'disposable-test-password' );
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$result = activate_plugin( 'petit-form/petit-form.php' );
if ( is_wp_error( $result ) ) {
	throw new RuntimeException( $result->get_error_message() );
}
echo 'WordPress ' . get_bloginfo( 'version' ) . ', PHP ' . PHP_VERSION . ', database ' . $wpdb->db_version() . "\n";
