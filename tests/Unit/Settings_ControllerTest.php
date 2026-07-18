<?php

namespace Photolab;

use PHPUnit\Framework\TestCase;

$GLOBALS['_photolab_current_user_can_override'] = null;

if ( ! function_exists( __NAMESPACE__ . '\current_user_can' ) ) {
	function current_user_can( ...$args ) {
		if ( null !== $GLOBALS['_photolab_current_user_can_override'] ) {
			return $GLOBALS['_photolab_current_user_can_override'];
		}
		return \current_user_can( ...$args );
	}
}

class Settings_ControllerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_photolab_test_options']              = array();
		$GLOBALS['_photolab_current_user_can_override'] = null;
	}

	protected function tearDown(): void {
		parent::tearDown();
	}

	public function test_get_settings_returns_all_keys(): void {
		update_option( 'photolab_watermark_active', true );
		update_option( 'photolab_watermark_url', 'http://example.com/wm.png' );
		update_option( 'photolab_watermark_position', 'fullwidth' );
		update_option( 'photolab_image_engine', 'gd' );

		$controller = new Settings_Controller();
		$request    = new \WP_REST_Request();
		$result     = $controller->get_settings( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$data = $result->get_data();

		$this->assertArrayHasKey( 'watermark_active', $data );
		$this->assertArrayHasKey( 'watermark_url', $data );
		$this->assertArrayHasKey( 'watermark_position', $data );
		$this->assertArrayHasKey( 'image_engine', $data );
	}

	public function test_get_settings_returns_image_engine(): void {
		update_option( 'photolab_image_engine', 'imagick' );

		$controller = new Settings_Controller();
		$request    = new \WP_REST_Request();
		$result     = $controller->get_settings( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$data = $result->get_data();

		$this->assertSame( 'imagick', $data['image_engine'] );
	}

	public function test_get_settings_handles_missing_options(): void {
		$GLOBALS['_photolab_test_options'] = array();

		$controller = new Settings_Controller();
		$request    = new \WP_REST_Request();
		$result     = $controller->get_settings( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$data = $result->get_data();

		$this->assertFalse( $data['watermark_active'] );
		$this->assertSame( '', $data['watermark_url'] );
		$this->assertSame( 'bottom_right', $data['watermark_position'] );
		$this->assertSame( 'gd', $data['image_engine'] );
	}


	public function test_register_routes_does_not_throw(): void {
		$this->markTestSkipped( 'Requires real WordPress REST server (run via integration test).' );
	}

	public function test_admin_permissions_check_returns_error_for_non_admin(): void {
		$GLOBALS['_photolab_current_user_can_override'] = false;

		$controller = new Settings_Controller();
		$request    = new \WP_REST_Request();
		$result     = $controller->admin_permissions_check( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_admin_permissions_check_returns_true_for_admin(): void {
		$controller = new Settings_Controller();
		$request    = new \WP_REST_Request();
		$result     = $controller->admin_permissions_check( $request );

		$this->assertTrue( $result );
	}
}
