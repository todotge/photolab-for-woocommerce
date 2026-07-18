<?php
if ( file_exists( dirname( __DIR__ ) . '/.env.testing' ) ) {
    foreach ( file( dirname( __DIR__ ) . '/.env.testing', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
        if ( str_starts_with( trim( $line ), '#' ) ) continue;
        putenv( trim( $line ) );
    }
}

define( 'WP_TESTS_DIR', getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib' );
define( 'WP_CORE_DIR', getenv( 'WP_CORE_DIR' ) ?: '/tmp/wordpress' );
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );

require_once WP_TESTS_DIR . '/includes/functions.php';

function _manually_load_plugin() {
    $wc_main = getenv( 'WC_DIR' ) ?: '/tmp/woocommerce';
    if ( file_exists( $wc_main . '/woocommerce.php' ) ) {
        require $wc_main . '/woocommerce.php';
    }
    require_once dirname( __DIR__ ) . '/photolab.php';
    
    // Ensure Photolab tables exist — must run after WooCommerce is loaded
    // because Database::install() uses Logger which calls wc_get_logger().
    $database = new Photolab\Database();
    $database->install();
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

require_once WP_TESTS_DIR . '/includes/bootstrap.php';
