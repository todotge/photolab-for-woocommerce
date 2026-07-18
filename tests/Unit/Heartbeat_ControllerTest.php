<?php
/**
 * Unit tests for Heartbeat_Controller.
 *
 * @package Photolab
 */

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

class Heartbeat_ControllerTest extends TestCase {

	private Heartbeat_Controller $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->controller = new Heartbeat_Controller();
	}

	protected function tearDown(): void {
		$GLOBALS['_photolab_current_user_can_override'] = null;
		parent::tearDown();
	}

	public function test_register_routes_does_not_throw(): void {
		$this->markTestSkipped( 'Requires real WordPress REST server (run via integration test).' );
	}

	public function test_admin_permissions_check_returns_error_for_non_admin(): void {
		$GLOBALS['_photolab_current_user_can_override'] = false;

		$request = new \WP_REST_Request();
		$result  = $this->controller->admin_permissions_check( $request );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_handle_heartbeat_rejects_missing_album_id(): void {
		$request = new \WP_REST_Request();
		$result  = $this->controller->handle_heartbeat( $request );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'Invalid job ID', $result->get_error_message() );
	}

	public function test_handle_heartbeat_updates_timestamp_for_valid_album(): void {
		$orig            = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = new class() {
			public $last_error = '';
			public $prefix     = 'wp_';
			public $insert_id  = 0;

			public function prepare( $q, ...$args ) { return $q; }
			public function get_var( $q ) { return 0; }
			public function get_results( $q ) { return array(); }
			public function get_row( $q, $o = OBJECT ) { return null; }

			public function insert( $t, $d, $f = null ) {
				$this->insert_id = 42;
				return 1;
			}

			public function query( $q ) {
				return 1;
			}

			public function update( $t, $d, $w, $f = null ) { return 1; }
		};

		$request = new \WP_REST_Request();
		$request->set_param( 'job_id', 42 );

		$result = $this->controller->handle_heartbeat( $request );

		$GLOBALS['wpdb'] = $orig;

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertEquals( 200, $result->get_status() );
	}
}
