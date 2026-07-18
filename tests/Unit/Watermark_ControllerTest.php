<?php

namespace Photolab;

use PHPUnit\Framework\TestCase;

// Override helpers for WatermarkController tests.
$GLOBALS['_photolab_check_filetype_override']       = null;
$GLOBALS['_photolab_current_user_can_override'] = null;

if ( ! function_exists( __NAMESPACE__ . '\current_user_can' ) ) {
	function current_user_can( ...$args ) {
		if ( null !== $GLOBALS['_photolab_current_user_can_override'] ) {
			return $GLOBALS['_photolab_current_user_can_override'];
		}
		return \current_user_can( ...$args );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\wp_check_filetype_and_ext' ) ) {
	function wp_check_filetype_and_ext( string $file, string $filename ): array {
		if ( null !== $GLOBALS['_photolab_check_filetype_override'] ) {
			return $GLOBALS['_photolab_check_filetype_override'];
		}
		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		if ( 'png' === $ext ) {
			return array( 'ext' => 'png', 'type' => 'image/png', 'proper_filename' => $filename );
		}
		return \wp_check_filetype_and_ext( $file, $filename );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\move_uploaded_file' ) ) {
	function move_uploaded_file( string $from, string $to ): bool {
		if ( ! file_exists( $from ) ) {
			return false;
		}
		$dir = dirname( $to );
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}
		return copy( $from, $to );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\wp_delete_file' ) ) {
	function wp_delete_file( string $path ): bool {
		if ( file_exists( $path ) ) {
			return unlink( $path );
		}
		return true;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\esc_url_raw' ) ) {
	function esc_url_raw( string $url, $protocols = null ): string {
		return $url;
	}
}

class Watermark_ControllerTest extends TestCase {

	private string $temp_dir = '';

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_photolab_check_filetype_override']      = null;
		$GLOBALS['_photolab_current_user_can_override']    = null;
		$GLOBALS['_photolab_test_options']                 = array();
		$_FILES                                       = array();
		$this->temp_dir                               = sys_get_temp_dir() . '/photolab-wm-' . uniqid();
		mkdir( $this->temp_dir, 0755, true );
	}

	protected function tearDown(): void {
		$_FILES = array();
		$this->rmdir_recursive( $this->temp_dir );
		parent::tearDown();
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
	// upload
	// -------------------------------------------------------------------------

	public function test_upload_validates_file_param(): void {
		unset( $_FILES['watermark'] );

		$controller = new Watermark_Controller();
		$request    = new \WP_REST_Request();
		$result     = $controller->upload( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'missing_file', $result->get_error_code() );
	}

	public function test_upload_saves_file_to_assets(): void {
		$png = $this->temp_dir . '/test.png';
		file_put_contents( $png, 'fake-png-content' );

		$_FILES['watermark'] = array(
			'name'     => 'watermark.png',
			'type'     => 'image/png',
			'tmp_name' => $png,
			'error'    => UPLOAD_ERR_OK,
			'size'     => 100,
		);

		// Override wp_upload_dir for our temp dir.
		$GLOBALS['_photolab_upload_dir_basedir'] = $this->temp_dir;
		if ( ! function_exists( __NAMESPACE__ . '\wp_upload_dir' ) ) {
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

		$GLOBALS['_photolab_check_filetype_override'] = array(
			'ext'             => 'png',
			'type'            => 'image/png',
			'proper_filename' => 'watermark.png',
		);

		$controller = new Watermark_Controller();
		$request    = new \WP_REST_Request( 'POST', array( 'position' => 'bottom_right' ) );
		$result     = $controller->upload( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$data = $result->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertNotEmpty( $data['watermark_url'] );
		$this->assertSame( $this->temp_dir . '/Photolab/assets/watermark.png', get_option( 'photolab_watermark_path' ) );
		$this->assertSame( 1, get_option( 'photolab_watermark_active' ) );
	}

	public function test_upload_rejects_invalid_type(): void {
		$jpg = $this->temp_dir . '/test.jpg';
		file_put_contents( $jpg, 'fake-jpg-content' );

		$_FILES['watermark'] = array(
			'name'     => 'watermark.jpg',
			'type'     => 'image/jpeg',
			'tmp_name' => $jpg,
			'error'    => UPLOAD_ERR_OK,
			'size'     => 100,
		);

		$controller = new Watermark_Controller();
		$request    = new \WP_REST_Request();
		$result     = $controller->upload( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'invalid_type', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// delete
	// -------------------------------------------------------------------------

	public function test_delete_removes_file_and_option(): void {
		$wm_file = $this->temp_dir . '/watermark.png';
		file_put_contents( $wm_file, 'content' );
		update_option( 'photolab_watermark_path', $wm_file );
		update_option( 'photolab_watermark_url', 'http://example.com/watermark.png' );
		update_option( 'photolab_watermark_active', 1 );

		$controller = new Watermark_Controller();
		$request    = new \WP_REST_Request();
		$result     = $controller->delete( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertFileDoesNotExist( $wm_file );
		$this->assertFalse( get_option( 'photolab_watermark_path', false ) );
		$this->assertFalse( get_option( 'photolab_watermark_url', false ) );
		$this->assertSame( 0, get_option( 'photolab_watermark_active' ) );
	}

	// -------------------------------------------------------------------------
	// update_position
	// -------------------------------------------------------------------------

	public function test_update_position_persists(): void {
		$controller = new Watermark_Controller();
		$request    = new \WP_REST_Request();
		$request->set_param( 'position', 'fullwidth' );
		$result     = $controller->update_position( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame( 'fullwidth', get_option( 'photolab_watermark_position' ) );
		$data = $result->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertSame( 'fullwidth', $data['position'] );
	}


	public function test_register_routes_does_not_throw(): void {
		$this->markTestSkipped( 'Requires real WordPress REST server (run via integration test).' );
	}

	public function test_admin_permissions_check_returns_error_for_non_admin(): void {
		$GLOBALS['_photolab_current_user_can_override'] = false;

		$controller = new Watermark_Controller();
		$request    = new \WP_REST_Request();
		$result     = $controller->admin_permissions_check( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_admin_permissions_check_returns_true_for_admin(): void {
		$controller = new Watermark_Controller();
		$request    = new \WP_REST_Request();
		$result     = $controller->admin_permissions_check( $request );

		$this->assertTrue( $result );
	}
}
