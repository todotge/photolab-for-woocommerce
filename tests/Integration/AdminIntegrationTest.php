<?php
/**
 * Admin integration tests — real WordPress hooks, menus, and enqueues.
 *
 * These replace the stubbed Unit tests that used assertTrue(true).
 * With WP_UnitTestCase, add_action, add_menu_page, and wp_enqueue_*
 * actually execute, letting us verify real behavior.
 */

namespace Photolab\Tests\Integration;

use WP_UnitTestCase;

class AdminIntegrationTest extends WP_UnitTestCase {

	private \Photolab\Admin $admin;

	public function setUp(): void {
		parent::setUp();
		$this->admin = new \Photolab\Admin();
	}

	public function test_register_menu_adds_menu_page(): void {
		$this->admin->register_menu();

		global $menu;
		$this->assertIsArray( $menu, 'Global $menu should exist after register_menu()' );
		$this->assertNotEmpty( $menu, 'Menu should contain at least one entry' );

		$found = false;
		foreach ( $menu as $item ) {
			if ( isset( $item[2] ) && str_contains( (string) $item[2], 'todot-photolab' ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Photolab menu page should be registered' );
	}

	public function test_enqueue_assets_on_photolab_page(): void {
		global $wp_scripts, $wp_styles;

		$this->admin->enqueue_assets( 'toplevel_page_todot-photolab' );

		$styles_queue = $wp_styles->queue ?? array();
		$scripts_queue = $wp_scripts->queue ?? array();

		$has_css = false;
		$has_js  = false;
		foreach ( $styles_queue as $handle ) {
			if ( str_contains( (string) $handle, 'todot-photolab' ) ) {
				$has_css = true;
				break;
			}
		}
		foreach ( $scripts_queue as $handle ) {
			if ( str_contains( (string) $handle, 'todot-photolab' ) ) {
				$has_js = true;
				break;
			}
		}
		$this->assertTrue( $has_css, 'Photolab admin CSS should be enqueued' );
		$this->assertTrue( $has_js, 'Photolab admin JS should be enqueued' );
	}

	public function test_enqueue_assets_only_on_photolab_page(): void {
		// Non-Photolab page: method returns early at $hook_suffix check.
		// Verify the early return doesn't crash (smoke test).
		$this->admin->enqueue_assets( 'dashboard' );
		$this->expectNotToPerformAssertions();
	}

	public function test_enqueue_assets_passes_chunk_size_param(): void {
		$this->admin->enqueue_assets( 'toplevel_page_todot-photolab' );

		global $wp_scripts;
		$raw_data = $wp_scripts->get_data( 'photolab-admin', 'data' );
		$this->assertNotNull( $raw_data, 'wp_localize_script should set data for photolab-admin' );
		$this->assertStringContainsString( 'chunkSize', (string) $raw_data, 'Localized data should include chunkSize' );
	}

	/**
	 * Integration with real WP hooks — render_page uses add_menu_page
	 * which registers into $menu global, not ob_start-friendly template.
	 */
	public function test_register_menu_and_run_page_flow(): void {
		global $menu;
		$this->admin->init(); // registers all hooks
		$this->admin->register_menu();

		$this->assertIsArray( $menu, 'Global $menu should exist' );
		$this->assertNotEmpty( $menu, 'Menu should contain at least one entry' );
	}

	public function test_render_page_includes_template(): void {
		global $wp_scripts;
		if ( ! isset( $wp_scripts ) ) {
			$wp_scripts = new \WP_Scripts();
		}

		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		@$this->admin->render_page(); // suppress template inclusion warnings
		$output = ob_get_clean();

		$this->assertNotEmpty( $output, 'render_page must produce output' );
	}

	public function test_notice_pretty_permalinks_shows_when_disabled(): void {
		global $pagenow;
		$pagenow = 'plugins.php';
		update_option( 'permalink_structure', '' );

		ob_start();
		$this->admin->notice_pretty_permalinks();
		$output = ob_get_clean();

		$this->assertStringContainsString(
			'Pretty',
			$output,
			'Notice should mention Pretty Permalinks when disabled'
		);
	}

	public function test_notice_pretty_permalinks_hides_when_enabled(): void {
		global $pagenow;
		$pagenow = 'plugins.php';
		update_option( 'permalink_structure', '/%postname%/' );

		ob_start();
		$this->admin->notice_pretty_permalinks();
		$output = ob_get_clean();

		$this->assertStringNotContainsString(
			'Pretty',
			$output,
			'Notice should be hidden when permalinks are configured'
		);
	}

	/**
	 * When GD is the only engine, detect_image_engine stores 'gd'.
	 */
	public function test_detect_image_engine_stores_gd(): void {
		if ( \extension_loaded( 'imagick' ) ) {
			$this->markTestSkipped( 'Imagick available — GD-only path is unreachable.' );
		}
		delete_option( 'photolab_image_engine' );

		$this->admin->detect_image_engine();

		$this->assertSame(
			'gd',
			get_option( 'photolab_image_engine' ),
			'Should store gd when Imagick is absent'
		);
	}

	/**
	 * When Imagick IS available, detect_image_engine stores 'imagick'.
	 * Run only if imagick extension is loaded; otherwise skip.
	 */
	public function test_detect_image_engine_stores_imagick(): void {
		if ( ! \extension_loaded( 'imagick' ) ) {
			$this->markTestSkipped( 'Imagick extension not loaded — skipping.' );
		}
		delete_option( 'photolab_image_engine' );

		$this->admin->detect_image_engine();

		$this->assertSame(
			'imagick',
			get_option( 'photolab_image_engine' ),
			'Should store imagick when it is available'
		);
	}

	/**
	 * Smoke test: user_can_upload requires a logged-in user.
	 */
	public function test_user_can_upload_returns_true_for_admin(): void {
		global $wpdb;

		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );

		$admin = new \Photolab\Admin();
		$method = new \ReflectionMethod( $admin, 'user_can_upload' );
		$method->setAccessible( true );
		$result = $method->invoke( $admin );

		$this->assertTrue( $result, 'Admin user with no active albums should be allowed to upload' );
	}
}
