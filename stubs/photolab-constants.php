<?php
/**
 * Stub for runtime-defined Photolab constants.
 * Used by PHPStan static analysis only.
 *
 * @package Photolab
 */

if ( ! defined( 'PHOTOLAB_VERSION' ) ) {
	define( 'PHOTOLAB_VERSION', '0.0.5' );
}
if ( ! defined( 'PHOTOLAB_DB_VERSION' ) ) {
	define( 'PHOTOLAB_DB_VERSION', '1.0.0' );
}
if ( ! defined( 'PHOTOLAB_PLUGIN_FILE' ) ) {
	define( 'PHOTOLAB_PLUGIN_FILE', dirname( __DIR__ ) . '/photolab.php' );
}
if ( ! defined( 'PHOTOLAB_PLUGIN_DIR' ) ) {
	define( 'PHOTOLAB_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'PHOTOLAB_PLUGIN_URL' ) ) {
	define( 'PHOTOLAB_PLUGIN_URL', 'https://example.com/wp-content/plugins/photolab/' );
}
if ( ! defined( 'PHOTOLAB_MIN_PHP' ) ) {
	define( 'PHOTOLAB_MIN_PHP', '8.0' );
}
if ( ! defined( 'PHOTOLAB_MIN_WP' ) ) {
	define( 'PHOTOLAB_MIN_WP', '6.0' );
}
if ( ! defined( 'PHOTOLAB_MIN_WC' ) ) {
	define( 'PHOTOLAB_MIN_WC', '8.0' );
}
