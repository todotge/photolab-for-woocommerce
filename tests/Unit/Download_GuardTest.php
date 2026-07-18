<?php
/**
 * Unit tests for Download_Guard.
 *
 * @package Photolab
 */

namespace Photolab;

use PHPUnit\Framework\TestCase;

// phpcs:disable NeutronStandard.Functions.TypeHint.NoArgumentType -- stubs

if ( ! function_exists( __NAMESPACE__ . '\add_filter' ) ) {
	function add_filter( string $tag, callable $callback, int $priority = 10, int $_accepted_args = 1 ): void {
		$GLOBALS['_photolab_filters'][ $tag ][] = array(
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $_accepted_args,
		);
	}
}

if ( ! function_exists( __NAMESPACE__ . '\has_filter' ) ) {
	function has_filter( string $tag, $callback = null ) {
		if ( ! isset( $GLOBALS['_photolab_filters'][ $tag ] ) ) {
			return false;
		}
		return count( $GLOBALS['_photolab_filters'][ $tag ] );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\wc_get_logger' ) ) {
	function wc_get_logger() {
		if ( ! isset( $GLOBALS['_photolab_logger'] ) ) {
			$GLOBALS['_photolab_logger'] = new class() {
				public array $logs = array();

				public function __call( string $name, array $args ) {
					$this->logs[] = array(
						'level'   => $name,
						'message' => $args[0],
						'context' => $args[1] ?? array(),
					);
				}
			};
		}
		return $GLOBALS['_photolab_logger'];
	}
}

// phpcs:enable

/**
 * Verify Download_Guard::filter_download_path and related helpers.
 */
class Download_GuardTest extends TestCase {

	/**
	 * Clean up filter/action globals and logger state before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_photolab_filters']       = array();
		$GLOBALS['_photolab_actions']        = array();
		$GLOBALS['_photolab_do_action']       = array();
		if ( isset( $GLOBALS['_photolab_logger'] ) ) {
			$GLOBALS['_photolab_logger']->logs = array();
		}
	}

	/**
	 * init registers the woocommerce_product_file_download_path filter.
	 */
	public function test_init_registers_woocommerce_filter(): void {
		Download_Guard::init();
		$this->assertGreaterThan( 0, has_filter( 'woocommerce_product_file_download_path' ) );
	}

	/**
	 * lookup_photo_status returns the status string for a Photolab product.
	 */
	public function test_lookup_photo_status_returns_status(): void {
		$orig = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = new class() {
			public string $last_error = '';
			public string $prefix     = 'wp_';
			public function prepare( string $q, mixed ...$a ): string { return $q; }
			public function get_var( string $q ) { return 'watermarked'; }
		};

		$method = new \ReflectionMethod( Download_Guard::class, 'lookup_photo_status' );
		$method->setAccessible( true );
		$result = $method->invoke( null, 42 );

		$this->assertSame( 'watermarked', $result );
		$GLOBALS['wpdb'] = $orig;
	}

	/**
	 * lookup_photo_status returns null when no matching row exists.
	 */
	public function test_lookup_photo_status_returns_null_when_not_found(): void {
		$orig = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = new class() {
			public string $last_error = '';
			public string $prefix     = 'wp_';
			public function prepare( string $q, mixed ...$a ): string { return $q; }
			public function get_var( string $q ) { return null; }
		};

		$method = new \ReflectionMethod( Download_Guard::class, 'lookup_photo_status' );
		$method->setAccessible( true );
		$result = $method->invoke( null, 42 );

		$this->assertNull( $result );
		$GLOBALS['wpdb'] = $orig;
	}

	/**
	 * Non-Photolab file paths pass through unchanged.
	 */
	public function test_filter_download_path_returns_original_when_not_photolab(): void {
		$orig = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = new class() {
			public string $last_error = '';
			public string $prefix     = 'wp_';
			public function prepare( string $q, mixed ...$a ): string { return $q; }
			public function get_var( string $q ) { return null; }
		};

		$result = Download_Guard::filter_download_path(
			'/uploads/other/file.pdf',
			$this->make_product( 1 ),
			'dl_token'
		);
		$this->assertSame( '/uploads/other/file.pdf', $result );

		$GLOBALS['wpdb'] = $orig;
	}

	/**
	 * When the photo is not watermarked, the download is blocked.
	 */
	public function test_filter_download_path_returns_modified_when_not_watermarked(): void {
		$orig = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = new class() {
			public string $last_error = '';
			public string $prefix     = 'wp_';
			public function prepare( string $q, mixed ...$a ): string { return $q; }
			public function get_var( string $q ) { return 'uploaded'; }
		};

		try {
			$this->expectException( \RuntimeException::class );
			Download_Guard::filter_download_path(
				'/uploads/Photolab/watermarked/photo.jpg',
				$this->make_product( 42 ),
				'dl_token'
			);
		} finally {
			$GLOBALS['wpdb'] = $orig;
		}
	}

	/**
	 * When the photo is watermarked, the path is returned unchanged.
	 */
	public function test_filter_download_path_returns_original_when_watermarked(): void {
		$orig = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = new class() {
			public string $last_error = '';
			public string $prefix     = 'wp_';
			public function prepare( string $q, mixed ...$a ): string { return $q; }
			public function get_var( string $q ) { return 'watermarked'; }
		};

		$result = Download_Guard::filter_download_path(
			'/uploads/Photolab/watermarked/photo.jpg',
			$this->make_product( 42 ),
			'dl_token'
		);

		$this->assertSame( '/uploads/Photolab/watermarked/photo.jpg', $result );
		$GLOBALS['wpdb'] = $orig;
	}

	/**
	 * null product does not cause an error.
	 */
	public function test_filter_download_path_handles_null_product(): void {
		$result = Download_Guard::filter_download_path(
			'/uploads/file.pdf',
			null,
			'dl_token'
		);
		$this->assertSame( '/uploads/file.pdf', $result );
	}

	/**
	 * Create a minimal product stub.
	 *
	 * @param int $id Product id.
	 * @return object
	 */
	private function make_product( int $id ): object {
		return new class( $id ) {
			public function __construct( private int $pid ) {}
			public function get_id(): int { return $this->pid; }
		};
	}
}
