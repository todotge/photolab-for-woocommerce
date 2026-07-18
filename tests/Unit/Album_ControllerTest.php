<?php

namespace Photolab;

use PHPUnit\Framework\TestCase;

class Album_ControllerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->reset_globals();
	}

	protected function tearDown(): void {
		$this->reset_globals();
		parent::tearDown();
	}

	private function reset_globals(): void {
		$GLOBALS['_photolab_deleted_posts']       = array();
		$GLOBALS['_photolab_deleted_attachments'] = array();
		$GLOBALS['_photolab_test_options']        = array();
		$GLOBALS['wpdb']->last_error              = '';
	}

	// -------------------------------------------------------------------------
	// get_items
	// -------------------------------------------------------------------------

	public function test_get_items_returns_paginated_list(): void {
		$orig                 = $GLOBALS['wpdb'];
		$GLOBALS['wpdb']      = new class() {
			public $last_error = '';
			public $prefix     = 'wp_';

			public function prepare( $q, ...$args ) { return $q; }
			public function get_var( $q ) { return 5; }
			public function get_row( $q, $o = OBJECT ) { return null; }

			public function get_results( $q ) {
				return array(
					array(
						'id'              => 1,
						'album_name'      => 'Album A',
						'status'          => 'idle',
						'expiration_date' => null,
						'created_at'      => '2025-01-01 00:00:00',
						'photo_count'     => '3',
					),
					array(
						'id'              => 2,
						'album_name'      => 'Album B',
						'status'          => 'watermarking',
						'expiration_date' => null,
						'created_at'      => '2025-01-02 00:00:00',
						'photo_count'     => '0',
					),
				);
			}
			public function insert( $t, $d, $f = null ) { return 1; }
			public function delete( $t, $w, $f = null ) { return 1; }
			public function query( $q ) { return 1; }
		};

		$controller = new Album_Controller();
		$request    = new \WP_REST_Request();
		$request->set_param( 'page', 1 );
		$request->set_param( 'per_page', 10 );

		$result = $controller->get_items( $request );
		$GLOBALS['wpdb'] = $orig;

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$data = $result->get_data();
		$this->assertArrayHasKey( 'albums', $data );
		$this->assertCount( 2, $data['albums'] );
		$this->assertSame( 5, $data['total'] );
		$this->assertSame( 1, $data['total_pages'] );
	}

	public function test_get_items_includes_expiration_date(): void {
		$orig                 = $GLOBALS['wpdb'];
		$GLOBALS['wpdb']      = new class() {
			public $last_error = '';
			public $prefix     = 'wp_';

			public function prepare( $q, ...$args ) { return $q; }
			public function get_var( $q ) { return 1; }
			public function get_row( $q, $o = OBJECT ) { return null; }

			public function get_results( $q ) {
				return array(
					array(
						'id'              => 1,
						'album_name'      => 'Expiring Album',
						'status'          => 'idle',
						'expiration_date' => '2026-12-31 00:00:00',
						'created_at'      => '2025-01-01 00:00:00',
						'photo_count'     => '5',
					),
				);
			}
			public function insert( $t, $d, $f = null ) { return 1; }
			public function delete( $t, $w, $f = null ) { return 1; }
			public function query( $q ) { return 1; }
		};

		$controller = new Album_Controller();
		$request    = new \WP_REST_Request();
		$request->set_param( 'page', 1 );
		$request->set_param( 'per_page', 10 );

		$result = $controller->get_items( $request );
		$GLOBALS['wpdb'] = $orig;

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$data  = $result->get_data();
		$album = $data['albums'][0];
		$this->assertSame( '2026-12-31', $album['expiration_date'] );
	}

	public function test_get_items_handles_empty(): void {
		$orig                 = $GLOBALS['wpdb'];
		$GLOBALS['wpdb']      = new class() {
			public $last_error = '';
			public $prefix     = 'wp_';

			public function prepare( $q, ...$args ) { return $q; }
			public function get_var( $q ) { return 0; }
			public function get_row( $q, $o = OBJECT ) { return null; }
			public function get_results( $q ) { return array(); }
			public function insert( $t, $d, $f = null ) { return 1; }
			public function delete( $t, $w, $f = null ) { return 1; }
			public function query( $q ) { return 1; }
		};

		$controller = new Album_Controller();
		$request    = new \WP_REST_Request();
		$request->set_param( 'page', 1 );
		$request->set_param( 'per_page', 10 );

		$result = $controller->get_items( $request );
		$GLOBALS['wpdb'] = $orig;

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$data = $result->get_data();
		$this->assertSame( array(), $data['albums'] );
		$this->assertSame( 0, $data['total'] );
		$this->assertSame( 0, $data['total_pages'] );
	}

	// -------------------------------------------------------------------------
	// delete_item
	// -------------------------------------------------------------------------

	public function test_delete_item_deletes_photos_and_album(): void {
		$orig            = $GLOBALS['wpdb'];
		$GLOBALS['_photolab_get_results_call_count'] = 0;

		$GLOBALS['wpdb'] = new class() {
			public $last_error = '';
			public $prefix     = 'wp_';
			public function prepare( $q, ...$args ) { return $q; }
			public function get_var( $q ) { return 0; }

			public function get_row( $q, $o = OBJECT ) {
				return (object) array(
					'id'         => 500,
					'album_name' => 'delete-test',
					'user_id'    => 1,
					'status'     => 'idle',
					'term_id'    => 10,
				);
			}

			public function get_results( $q ) {
				++$GLOBALS['_photolab_get_results_call_count'];
				if ( $GLOBALS['_photolab_get_results_call_count'] > 1 ) {
					return array();
				}
				return array(
					array(
						'id'             => 1,
						'attachment_id'  => 100,
						'wc_product_id'  => 200,
					),
				);
			}

			public function insert( $t, $d, $f = null ) { return 1; }
			public function delete( $t, $w, $f = null ) { return 1; }
			public function query( $q ) { return 1; }
		};

		$this->assertCount( 0, $GLOBALS['_photolab_deleted_posts'] );
		$this->assertCount( 0, $GLOBALS['_photolab_deleted_attachments'] );

		$controller = new Album_Controller();
		$request    = new \WP_REST_Request();
		$request->set_param( 'id', 500 );

		$result = $controller->delete_item( $request );
		$GLOBALS['wpdb'] = $orig;

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertContains( 200, $GLOBALS['_photolab_deleted_posts'] );
		$this->assertContains( 100, $GLOBALS['_photolab_deleted_attachments'] );
	}

	public function test_delete_item_handles_nonexistent(): void {
		$orig            = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = new class() {
			public $last_error = '';
			public $prefix     = 'wp_';
			public function prepare( $q, ...$args ) { return $q; }
			public function get_var( $q ) { return 0; }
			public function get_row( $q, $o = OBJECT ) { return null; }
			public function get_results( $q ) { return array(); }
			public function insert( $t, $d, $f = null ) { return 1; }
			public function delete( $t, $w, $f = null ) { return 1; }
			public function query( $q ) { return 1; }
		};

		$controller = new Album_Controller();
		$request    = new \WP_REST_Request();
		$request->set_param( 'id', 9999 );

		$result = $controller->delete_item( $request );
		$GLOBALS['wpdb'] = $orig;

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'not_found', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// reset_item
	// -------------------------------------------------------------------------

	public function test_reset_item_changes_status(): void {
		$orig            = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = new class() {
			public $last_error = '';
			public $prefix     = 'wp_';
			public function prepare( $q, ...$args ) { return $q; }
			public function get_var( $q ) { return 0; }

			public function get_row( $q, $o = OBJECT ) {
				return (object) array(
					'id'         => 500,
					'album_name' => 'aborted-album',
					'user_id'    => 1,
					'status'     => 'aborted',
				);
			}

			public function get_results( $q ) { return array(); }
			public function insert( $t, $d, $f = null ) { return 1; }
			public function delete( $t, $w, $f = null ) { return 1; }
			public function query( $q ) { return 1; }
		};

		$controller = new Album_Controller();
		$request    = new \WP_REST_Request();
		$request->set_param( 'id', 500 );

		$result = $controller->reset_item( $request );
		$GLOBALS['wpdb'] = $orig;

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$data = $result->get_data();
		$this->assertTrue( $data['ok'] );
		$this->assertSame( 'idle', $data['new_status'] );
	}

	public function test_reset_item_rejects_invalid_status(): void {
		$orig            = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = new class() {
			public $last_error = '';
			public $prefix     = 'wp_';
			public function prepare( $q, ...$args ) { return $q; }
			public function get_var( $q ) { return 0; }

			public function get_row( $q, $o = OBJECT ) {
				return (object) array(
					'id'         => 500,
					'album_name' => 'idle-album',
					'user_id'    => 1,
					'status'     => 'idle',
				);
			}

			public function get_results( $q ) { return array(); }
			public function insert( $t, $d, $f = null ) { return 1; }
			public function delete( $t, $w, $f = null ) { return 1; }
			public function query( $q ) { return 0; }
		};

		$controller = new Album_Controller();
		$request    = new \WP_REST_Request();
		$request->set_param( 'id', 500 );

		$result = $controller->reset_item( $request );
		$GLOBALS['wpdb'] = $orig;

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'photolab_conflict', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// cleanup_unwatermarked_photos
	// -------------------------------------------------------------------------

	public function test_cleanup_unwatermarked_photos_removes_orphans(): void {
		$orig            = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = new class() {
			public $last_error = '';
			public $prefix     = 'wp_';
			public function prepare( $q, ...$args ) { return $q; }
			public function get_var( $q ) { return 0; }

			public function get_results( $q ) {
				return array(
					(object) array( 'id' => 10, 'wc_product_id' => 300 ),
					(object) array( 'id' => 11, 'wc_product_id' => 301 ),
				);
			}

			public function insert( $t, $d, $f = null ) { return 1; }
			public function delete( $t, $w, $f = null ) { return 1; }
			public function query( $q ) { return 1; }
		};

		$controller = new Album_Controller();
		$ref        = new \ReflectionMethod( $controller, 'cleanup_unwatermarked_photos' );
		$ref->setAccessible( true );
		$ref->invokeArgs( $controller, array( 500, 'test-album', array( 'source' => 'test' ) ) );

		$GLOBALS['wpdb'] = $orig;

		$this->assertContains( 300, $GLOBALS['_photolab_deleted_posts'] );
		$this->assertContains( 301, $GLOBALS['_photolab_deleted_posts'] );
	}


	public function test_register_routes_does_not_throw(): void {
		$this->markTestSkipped( 'Requires real WordPress REST server (run via integration test).' );
	}

	public function test_admin_permissions_check_returns_error_for_non_admin(): void {
		$this->markTestSkipped( 'Requires real WordPress REST server (run via integration test).' );
	}
}
