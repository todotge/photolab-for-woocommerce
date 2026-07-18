<?php

namespace Photolab;

use PHPUnit\Framework\TestCase;

class Photo_ControllerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_photolab_test_options'] = array();
		$GLOBALS['wpdb']->last_error       = '';
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_photolab_get_row_album_count'] );
		unset( $GLOBALS['_photolab_get_row_status_count'] );
		parent::tearDown();
	}

	public function test_get_watermark_status_returns_counts(): void {
		$orig                 = $GLOBALS['wpdb'];
		$GLOBALS['_photolab_get_row_album_count']  = 0;
		$GLOBALS['_photolab_get_row_status_count'] = 0;

		$GLOBALS['wpdb'] = new class() {
			public $last_error = '';
			public $prefix     = 'wp_';
			public function prepare( $q, ...$args ) { return $q; }

			public function get_row( $q, $output = OBJECT ) {
				if ( ARRAY_A === $output ) {
					++$GLOBALS['_photolab_get_row_status_count'];
					return array(
						'pending'   => '5',
						'completed' => '10',
						'failed'    => '2',
					);
				}
				++$GLOBALS['_photolab_get_row_album_count'];
				return (object) array(
					'id'         => 1,
					'album_name' => 'Test Album',
					'user_id'    => 1,
					'status'     => 'watermarking',
				);
			}

			public function get_var( $q ) { return 0; }
			public function get_results( $q ) { return array(); }
			public function insert( $t, $d, $f = null ) { return 1; }
			public function delete( $t, $w, $f = null ) { return 1; }
			public function query( $q ) { return 1; }
		};

		$controller = new Photo_Controller();
		$request    = new \WP_REST_Request();
		$request->set_param( 'album_id', 1 );

		$result = $controller->get_watermark_status( $request );
		$GLOBALS['wpdb'] = $orig;

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$data = $result->get_data();
		$this->assertSame( 1, $data['album_id'] );
		$this->assertSame( 5, $data['pending'] );
		$this->assertSame( 10, $data['completed'] );
		$this->assertSame( 2, $data['failed'] );
		$this->assertSame( 17, $data['total'] );
	}

	public function test_get_watermark_status_caches_in_transient(): void {
		$orig                 = $GLOBALS['wpdb'];
		$GLOBALS['_photolab_get_row_album_count']  = 0;
		$GLOBALS['_photolab_get_row_status_count'] = 0;

		$GLOBALS['wpdb'] = new class() {
			public $last_error = '';
			public $prefix     = 'wp_';
			public function prepare( $q, ...$args ) { return $q; }

			public function get_row( $q, $output = OBJECT ) {
				if ( ARRAY_A === $output ) {
					++$GLOBALS['_photolab_get_row_status_count'];
					return array(
						'pending'   => '3',
						'completed' => '7',
						'failed'    => '0',
					);
				}
				++$GLOBALS['_photolab_get_row_album_count'];
				return (object) array(
					'id'         => 2,
					'album_name' => 'Cached Album',
					'user_id'    => 1,
					'status'     => 'watermarking',
				);
			}

			public function get_var( $q ) { return 0; }
			public function get_results( $q ) { return array(); }
			public function insert( $t, $d, $f = null ) { return 1; }
			public function delete( $t, $w, $f = null ) { return 1; }
			public function query( $q ) { return 1; }
		};

		// First call: should query DB and cache.
		$controller = new Photo_Controller();
		$request    = new \WP_REST_Request();
		$request->set_param( 'album_id', 2 );

		$controller->get_watermark_status( $request );
		$first_status_count = $GLOBALS['_photolab_get_row_status_count'];

		// Second call: should use cache, not re-query.
		$result2 = $controller->get_watermark_status( $request );
		$second_status_count = $GLOBALS['_photolab_get_row_status_count'];

		$GLOBALS['wpdb'] = $orig;

		$this->assertSame( $first_status_count, $second_status_count, 'Status query should not re-execute when transient is set' );
		$this->assertInstanceOf( \WP_REST_Response::class, $result2 );
		$data = $result2->get_data();
		$this->assertSame( 3, $data['pending'] );
	}

	public function test_get_watermark_status_returns_error_on_invalid_album(): void {
		$controller = new Photo_Controller();
		$request    = new \WP_REST_Request();
		$request->set_param( 'album_id', 0 );

		$result = $controller->get_watermark_status( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'invalid_album', $result->get_error_code() );
	}


    public function test_register_routes_does_not_throw(): void {
        $this->markTestSkipped( 'Requires real WordPress REST server (run via integration test).' );
    }

    public function test_admin_permissions_check_returns_error_for_non_admin(): void {
        $this->markTestSkipped( 'Requires real WordPress REST server (run via integration test).' );
    }

}
