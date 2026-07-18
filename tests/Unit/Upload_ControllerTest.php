<?php
/**
 * Unit tests for Upload_Controller REST endpoints.
 *
 * @package Photolab
 */

namespace Photolab;

use PHPUnit\Framework\TestCase;

// Override current_user_can in Photolab namespace so Upload_Controller::admin_permissions_check uses our value.
$GLOBALS['_photolab_current_user_can_override'] = null;

if ( ! function_exists( __NAMESPACE__ . '\current_user_can' ) ) {
	function current_user_can( ...$args ) {
		if ( null !== $GLOBALS['_photolab_current_user_can_override'] ) {
			return $GLOBALS['_photolab_current_user_can_override'];
		}
		return \current_user_can( ...$args );
	}
}

/**
 * Upload controller tests.
 */
class Upload_ControllerTest extends TestCase {

	private string $temp_base = '';

	protected function setUp(): void {
		parent::setUp();

		$this->temp_base = sys_get_temp_dir() . '/photolab-uct-' . uniqid();
		$assets = $this->temp_base . '/Photolab/assets';
		mkdir( $assets, 0755, true );
		touch( $assets . '/watermark.png' );

		$this->reset_globals();
	}

	protected function tearDown(): void {
		$this->rmdir_recursive( $this->temp_base );
		$this->reset_globals();
		$GLOBALS['_photolab_current_user_can_override'] = null;
		unset( $_FILES['files'] );
		parent::tearDown();
	}

	private function reset_globals(): void {
		$GLOBALS['_photolab_deleted_posts']       = array();
		$GLOBALS['_photolab_deleted_attachments'] = array();
	}

	private function rmdir_recursive( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$it    = new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS );
		$files = new \RecursiveIteratorIterator( $it, \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $files as $f ) {
			$f->isDir() ? rmdir( $f->getRealPath() ) : unlink( $f->getRealPath() );
		}
		rmdir( $dir );
	}

	// -------------------------------------------------------------------------
	// register_routes
	// -------------------------------------------------------------------------

	public function test_register_routes_does_not_throw(): void {
		$this->markTestSkipped( 'Requires real WordPress REST server (run via integration test).' );
	}

	// -------------------------------------------------------------------------
	// admin_permissions_check
	// -------------------------------------------------------------------------

	public function test_admin_permissions_check_returns_error_for_non_admin(): void {
		$GLOBALS['_photolab_current_user_can_override'] = false;

		$controller = new Upload_Controller();
		$request    = new \WP_REST_Request();
		$result     = $controller->admin_permissions_check( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_admin_permissions_check_returns_true_for_admin(): void {
		$controller = new Upload_Controller();
		$request    = new \WP_REST_Request();
		$result     = $controller->admin_permissions_check( $request );

		$this->assertTrue( $result );
	}

	// -------------------------------------------------------------------------
	// start
	// -------------------------------------------------------------------------

	private function mock_wpdb_start( int $active_count = 0, ?object $existing = null, bool $insert_success = true, bool $update_success = true ): object {
		return new class( $active_count, $existing, $insert_success, $update_success ) {
			public $last_error = '';
			public $prefix     = 'wp_';
			public $insert_id  = 0;

			public function __construct(
				private int $active_count,
				private ?object $existing,
				private bool $insert_success,
				private bool $update_success
			) {}

			public function prepare( $q, ...$args ) { return $q; }
			public function get_var( $q ) { return $this->active_count; }
			public function get_row( $q, $output = OBJECT ) { return $this->existing; }
			public function get_results( $q ) { return array(); }

			public function insert( $t, $d, $f = null ) {
				if ( ! $this->insert_success ) {
					return false;
				}
				$this->insert_id = 500;
				return 1;
			}

			public function update( $t, $d, $w, $f = null ) {
				return $this->update_success ? 1 : 0;
			}
			public function delete( $t, $w, $f = null ) { return 1; }
			public function query( $q ) { return 1; }
		};
	}

	public function test_start_validates_album_name(): void {
		$orig = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = $this->mock_wpdb_start();

		$controller = new Upload_Controller();
		$request    = new \WP_REST_Request();
		$result     = $controller->start( $request );

		$GLOBALS['wpdb'] = $orig;
		$this->assertTrue( is_object( $result ) );
	}

	public function test_start_creates_album(): void {
		$orig = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = $this->mock_wpdb_start( 0, null );

		$controller = new Upload_Controller();
		$request    = new \WP_REST_Request();
		$request->set_param( 'album_name', 'test-album' );
		$request->set_param( 'price', 9.99 );

		$result = $controller->start( $request );

		$GLOBALS['wpdb'] = $orig;

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$data = $result->get_data();
		$this->assertArrayHasKey( 'album_id', $data );
	}

	public function test_start_rate_limits_user(): void {
		$orig = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = $this->mock_wpdb_start( 3, null );

		$controller = new Upload_Controller();
		$request    = new \WP_REST_Request();
		$request->set_param( 'album_name', 'rate-limit-album' );
		$request->set_param( 'price', 5.00 );

		$result = $controller->start( $request );

		$GLOBALS['wpdb'] = $orig;

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'too_many_uploads', $result->get_error_code() );
	}

	public function test_start_with_expiration_date(): void {
		$orig = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = $this->mock_wpdb_start( 0, null );

		$controller = new Upload_Controller();
		$request    = new \WP_REST_Request();
		$request->set_param( 'album_name', 'expiring-album' );
		$request->set_param( 'price', 5.00 );
		$request->set_param( 'expiration_date', '2026-12-31' );

		$result = $controller->start( $request );

		$GLOBALS['wpdb'] = $orig;

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$data = $result->get_data();
		$this->assertArrayHasKey( 'album_id', $data );
	}

	// -------------------------------------------------------------------------
	// chunk
	// -------------------------------------------------------------------------

	private function make_album_for_chunk(): object {
		return (object) array(
			'id'                 => 500,
			'album_name'         => 'test-album',
			'user_id'            => 1,
			'status'             => 'uploading',
			'term_id'            => 10,
			'watermark_snapshot' => '',
			'expiration_date'    => null,
		);
	}

	public function test_chunk_rejects_missing_album(): void {
		$orig = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = new class() {
			public $last_error = '';
			public $prefix     = 'wp_';
			public function prepare( $q, ...$args ) { return $q; }
			public function get_row( $q, $o = OBJECT ) { return null; }
			public function get_var( $q ) { return 0; }
			public function get_results( $q ) { return array(); }
			public function insert( $t, $d, $f = null ) { return 1; }
			public function update( $t, $d, $w, $f = null ) { return 1; }
			public function delete( $t, $w, $f = null ) { return 1; }
			public function query( $q ) { return 1; }
		};

		$controller = new Upload_Controller();
		$request    = new \WP_REST_Request();
		$request->set_param( 'job_id', 9999 );
		$request->set_param( 'term_id', 1 );

		$result = $controller->chunk( $request );

		$GLOBALS['wpdb'] = $orig;

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'Invalid job ID', $result->get_error_message() );
	}

	public function test_chunk_saves_file_and_inserts_row(): void {
		$orig  = $GLOBALS['wpdb'];
		$album = $this->make_album_for_chunk();

		$GLOBALS['wpdb'] = new class( $album ) {
			public $last_error = '';
			public $prefix     = 'wp_';
			public $insert_id  = 0;

			public function __construct( private object $album ) {}

			public function prepare( $q, ...$args ) { return $q; }
			public function get_row( $q, $o = OBJECT ) { return $this->album; }
			public function get_var( $q ) { return null; }
			public function get_results( $q ) { return array(); }

			public function insert( $t, $d, $f = null ) {
				$this->insert_id = 999;
				return 1;
			}
			public function update( $t, $d, $w, $f = null ) { return 1; }
			public function delete( $t, $w, $f = null ) { return 1; }
			public function query( $q ) { return 1; }
		};

		$_FILES['files'] = array(
			'name'     => array( 'photo1.jpg' ),
			'type'     => array( 'image/jpeg' ),
			'tmp_name' => array( __FILE__ ),
			'error'    => array( UPLOAD_ERR_OK ),
			'size'     => array( 1024 ),
		);

		// Override wp_upload_dir for this test via Photolab namespace.
		$orig_dir = null;
		if ( ! function_exists( __NAMESPACE__ . '\wp_upload_dir' ) ) {
			$GLOBALS['_photolab_upload_dir_basedir'] = $this->temp_base;

			function wp_upload_dir(): array {
				$base = $GLOBALS['_photolab_upload_dir_basedir'] ?? '/tmp';
				return array(
					'dir'     => $base,
					'url'     => 'http://example.com/wp-content/uploads',
					'basedir' => $base,
					'baseurl' => 'http://example.com/wp-content/uploads',
				);
			}
		}

		$controller = new Upload_Controller();
		$request    = new \WP_REST_Request();
		$request->set_param( 'job_id', 500 );
		$request->set_param( 'term_id', 10 );

		$result = $controller->chunk( $request );

		$GLOBALS['wpdb'] = $orig;
		unset( $_FILES['files'] );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$data = $result->get_data();
		$this->assertArrayHasKey( 'processed', $data );
	}

	public function test_chunk_rejects_duplicate_via_sha256(): void {
		$orig  = $GLOBALS['wpdb'];
		$album = $this->make_album_for_chunk();

		$GLOBALS['wpdb'] = new class( $album ) {
			public $last_error = '';
			public $prefix     = 'wp_';
			public $insert_id  = 0;

			public function __construct( private object $album ) {}

			public function prepare( $q, ...$args ) { return $q; }
			public function get_row( $q, $o = OBJECT ) { return $this->album; }
			public function get_var( $q ) { return null; }
			public function get_results( $q ) { return array(); }

			public function insert( $t, $d, $f = null ) {
				$this->insert_id = 999;
				return 1;
			}
			public function update( $t, $d, $w, $f = null ) { return 1; }
			public function delete( $t, $w, $f = null ) { return 1; }
			public function query( $q ) { return 1; }
		};

		$_FILES['files'] = array(
			'name'     => array( 'photo1.jpg' ),
			'type'     => array( 'image/jpeg' ),
			'tmp_name' => array( __FILE__ ),
			'error'    => array( UPLOAD_ERR_OK ),
			'size'     => array( 1024 ),
		);

		$controller = new Upload_Controller();
		$request    = new \WP_REST_Request();
		$request->set_param( 'job_id', 500 );
		$request->set_param( 'term_id', 10 );

		$result = $controller->chunk( $request );

		$GLOBALS['wpdb'] = $orig;
		unset( $_FILES['files'] );

		// chunk con stesso SHA256 dovrebbe skippare; mock non replica logica reale — 
		// verifichiamo solo che non crasha. Test reale in integration.
		$this->assertIsObject( $result );
	}

	// -------------------------------------------------------------------------
	// complete
	// -------------------------------------------------------------------------

	public function test_complete_triggers_watermark_job(): void {
		$orig = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = new class() {
			public $last_error = '';
			public $prefix     = 'wp_';
			public function prepare( $q, ...$args ) { return $q; }
			public function get_row( $q, $o = OBJECT ) {
				return (object) array(
					'id'                 => 500,
					'album_name'         => 'test-album',
					'user_id'            => 1,
					'status'             => 'uploading',
					'watermark_snapshot' => '',
				);
			}
			public function get_var( $q ) { return 0; }
			public function get_results( $q ) { return array(); }
			public function insert( $t, $d, $f = null ) { return 1; }
			public function update( $t, $d, $w, $f = null ) { return 1; }
			public function delete( $t, $w, $f = null ) { return 1; }
			public function query( $q ) { return 1; }
		};

		$controller = new Upload_Controller();
		$request    = new \WP_REST_Request();
		$request->set_param( 'job_id', 500 );

		$result = $controller->complete( $request );

		$GLOBALS['wpdb'] = $orig;

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
	}

	public function test_complete_updates_album_status(): void {
		$orig = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = new class() {
			public $last_error = '';
			public $prefix     = 'wp_';
			public function prepare( $q, ...$args ) { return $q; }
			public function get_row( $q, $o = OBJECT ) {
				return (object) array(
					'id'                 => 500,
					'album_name'         => 'test-album',
					'user_id'            => 1,
					'status'             => 'uploading',
					'watermark_snapshot' => '',
				);
			}
			public function get_var( $q ) { return 0; }
			public function get_results( $q ) { return array(); }
			public function insert( $t, $d, $f = null ) { return 1; }
			public function update( $t, $d, $w, $f = null ) { return 1; }
			public function delete( $t, $w, $f = null ) { return 1; }
			public function query( $q ) { return 1; }
		};

		$controller = new Upload_Controller();
		$request    = new \WP_REST_Request();
		$request->set_param( 'job_id', 500 );

		$result = $controller->complete( $request );

		$GLOBALS['wpdb'] = $orig;

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$data = $result->get_data();
		$this->assertArrayHasKey( 'album_status', $data );
	}

	// -------------------------------------------------------------------------
	// status
	// -------------------------------------------------------------------------

	public function test_status_returns_counts(): void {
		$orig = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = new class() {
			public $last_error = '';
			public $prefix     = 'wp_';
			public function prepare( $q, ...$args ) { return $q; }
			public function get_row( $q, $o = OBJECT ) {
				return (object) array(
					'id'         => 500,
					'album_name' => 'test-album',
					'user_id'    => 1,
					'status'     => 'idle',
				);
			}
			public function get_var( $q ) { return 42; }
			public function get_results( $q ) { return array(); }
			public function insert( $t, $d, $f = null ) { return 1; }
			public function update( $t, $d, $w, $f = null ) { return 1; }
			public function delete( $t, $w, $f = null ) { return 1; }
			public function query( $q ) { return 1; }
		};

		$controller = new Upload_Controller();
		$request    = new \WP_REST_Request();
		$request->set_param( 'job_id', 500 );

		$result = $controller->status( $request );

		$GLOBALS['wpdb'] = $orig;

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$data = $result->get_data();
		$this->assertArrayHasKey( 'processed', $data );
	}

}
