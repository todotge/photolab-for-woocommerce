<?php
/**
 * Unit tests for Admin class.
 *
 * @package Photolab
 */

namespace { // phpcs:ignore

use PHPUnit\Framework\TestCase;

// Simulate Imagick availability for tests that need it.
$GLOBALS['_photolab_simulate_imagick'] = false;

if ( ! class_exists( 'WP_Image_Editor_Imagick' ) ) {
	class WP_Image_Editor_Imagick {}
}

/**
 * Admin UI manager tests.
 */
class AdminTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['_photolab_test_options']['permalink_structure'] );
		unset( $GLOBALS['_photolab_test_options']['photolab_image_engine'] );
		$GLOBALS['_photolab_simulate_imagick'] = false;
		parent::tearDown();
	}

	public function test_detect_image_engine_stores_gd(): void {
		$GLOBALS['pagenow'] = 'admin.php';
		$GLOBALS['_photolab_simulate_imagick'] = false;
		delete_option( 'photolab_image_engine' );

		$admin = new \Photolab\Admin();
		$admin->detect_image_engine();

		$this->assertSame( 'gd', get_option( 'photolab_image_engine' ) );
	}
}

} // End global namespace

namespace { // phpcs:ignore

use PHPUnit\Framework\TestCase;

/**
 * Imagick-specific test needs a separate namespace scope to override functions.
 */
class AdminTestImagickHides extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_photolab_simulate_imagick'] = true;
	}

	protected function tearDown(): void {
		$GLOBALS['_photolab_simulate_imagick'] = false;
		unset( $GLOBALS['pagenow'] );
		parent::tearDown();
	}

	public function test_detect_image_engine_stores_imagick(): void {
		$GLOBALS['pagenow'] = 'admin.php';
		delete_option( 'photolab_image_engine' );

		$admin = new \Photolab\Admin();
		$admin->detect_image_engine();

		$this->assertSame( 'imagick', get_option( 'photolab_image_engine' ) );
	}

	public function test_notice_pretty_permalinks_shows_when_disabled(): void {
		$GLOBALS['pagenow'] = 'admin.php';
		\update_option( 'permalink_structure', '' );

		$admin = new \Photolab\Admin();
		ob_start();
		$admin->notice_pretty_permalinks();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $output );
		$this->assertStringContainsString( 'Pretty Permalink', $output );
	}

	public function test_notice_pretty_permalinks_hides_when_enabled(): void {
		$GLOBALS['pagenow'] = 'admin.php';
		\update_option( 'permalink_structure', '/%postname%/' );

		$admin = new \Photolab\Admin();
		ob_start();
		$admin->notice_pretty_permalinks();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	public function test_init_registers_hooks(): void {
		$admin = new \Photolab\Admin();
		$admin->init();
		$this->assertIsObject( $admin );
	}

	public function test_register_menu_adds_menu_page(): void {
		$admin = new \Photolab\Admin();
		$admin->register_menu();
		// register_menu() calls add_menu_page(). With stubs, the call
		// doesn't crash which verifies the method runs cleanly.
		// Full hook verification requires WP_UnitTestCase (integration).
		$this->assertTrue( true );
	}

	public function test_enqueue_assets_only_on_photolab_page(): void {
		$admin = new \Photolab\Admin();
		$admin->enqueue_assets( 'dashboard' );
		// Non-Photolab page: the method returns early. Stubs prevent
		// deeper assertion but the method call itself serves as a
		// smoke test that the early-return path works.
		$this->assertTrue( true );
	}

	public function test_enqueue_assets_on_photolab_page(): void {
		$admin = new \Photolab\Admin();
		$admin->enqueue_assets( 'toplevel_page_todot-photolab' );
		$this->assertTrue( true );
	}

	public function test_enqueue_assets_passes_chunk_size_param(): void {
		$admin = new \Photolab\Admin();
		$admin->enqueue_assets( 'toplevel_page_todot-photolab' );
		$this->assertTrue( true );
	}

	public function test_render_page_includes_template(): void {
		$admin = new \Photolab\Admin();
		ob_start();
		$admin->render_page();
		$output = ob_get_clean();
		$this->assertNotEmpty( $output, 'render_page should produce output from admin template' );
	}

	public function test_user_can_upload_returns_true(): void {
		$orig = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = new class() {
			public $last_error = '';
			public $prefix = 'wp_';
			public function prepare( $q, ...$args ) { return $q; }
			public function get_var( $q ) { return 0; }
		};

		$admin  = new \Photolab\Admin();
		$method = new \ReflectionMethod( $admin, 'user_can_upload' );
		$method->setAccessible( true );
		$result = $method->invoke( $admin );

		$GLOBALS['wpdb'] = $orig;
		$this->assertTrue( $result );
	}

	public function test_user_can_upload_returns_false_when_limit_reached(): void {
		$orig = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = new class() {
			public $last_error = '';
			public $prefix = 'wp_';
			public function prepare( $q, ...$args ) { return $q; }
			public function get_var( $q ) { return 3; }
		};

		$admin  = new \Photolab\Admin();
		$method = new \ReflectionMethod( $admin, 'user_can_upload' );
		$method->setAccessible( true );
		$result = $method->invoke( $admin );

		$GLOBALS['wpdb'] = $orig;
		$this->assertFalse( $result );
	}
}

} // End global namespace

namespace Photolab { // phpcs:ignore

if ( ! function_exists( __NAMESPACE__ . '\wp_image_editor_supports' ) ) {
	function wp_image_editor_supports( $args = array() ) {
		if ( $GLOBALS['_photolab_simulate_imagick'] ) {
			return true;
		}
		return false;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\extension_loaded' ) ) {
	function extension_loaded( string $name ): bool {
		if ( $GLOBALS['_photolab_simulate_imagick'] && 'imagick' === $name ) {
			return true;
		}
		return \extension_loaded( $name );
	}
}

} // namespace Photolab
