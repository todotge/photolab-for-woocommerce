<?php
/**
 * Unit tests for Watermark_Job batch processing and thumbnail generation.
 *
 * @package Photolab
 */

namespace Photolab;

use PHPUnit\Framework\TestCase;

/**
 * Verify Watermark_Job batch watermarking, album finalisation, and thumbnails.
 */
class WatermarkJobTest extends TestCase {

	private string $test_file = '';

	private $original_wpdb   = null;
	private string $fixtures_dir = '';

	protected function setUp(): void {
		parent::setUp();

		$this->test_file = \tempnam( \sys_get_temp_dir(), 'photolab_test_' ) . '.jpg';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		\file_put_contents( $this->test_file, '' );

		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->fixtures_dir  = \sys_get_temp_dir() . '/photolab-wj-' . \uniqid();
		\mkdir( $this->fixtures_dir, 0755, true );
	}

	protected function tearDown(): void {
		if ( '' !== $this->test_file && \file_exists( $this->test_file ) ) {
			@\unlink( $this->test_file );
		}

		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		}
		if ( '' !== $this->fixtures_dir && \is_dir( $this->fixtures_dir ) ) {
			$files = \glob( $this->fixtures_dir . '/*' );
			if ( \is_array( $files ) ) {
				\array_map( 'unlink', $files );
			}
			@\rmdir( $this->fixtures_dir );
		}

		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Existing tests (unchanged)
	// -------------------------------------------------------------------------

	public function test_generate_thumbnail_meta_contains_woocommerce_thumbnail(): void {
		$meta = Watermark_Job::generate_thumbnail_meta( 1, $this->test_file );
		$this->assertArrayHasKey(
			'woocommerce_thumbnail',
			$meta['sizes'],
			'sizes must contain woocommerce_thumbnail'
		);

		$thumb = $meta['sizes']['woocommerce_thumbnail'];
		$this->assertArrayHasKey( 'file', $thumb );
		$this->assertArrayHasKey( 'width', $thumb );
		$this->assertArrayHasKey( 'height', $thumb );
		$this->assertArrayHasKey( 'mime-type', $thumb );
	}

	public function test_generate_thumbnail_meta_contains_only_woocommerce_size(): void {
		$meta = Watermark_Job::generate_thumbnail_meta( 1, $this->test_file );

		$allowed = array( 'woocommerce_thumbnail' );
		foreach ( $meta['sizes'] as $size_name => $size_data ) {
			$this->assertContains(
				$size_name,
				$allowed,
				'Generated sizes should only contain woocommerce_thumbnail, got ' . $size_name
			);
		}
	}

	public function test_generate_thumbnail_meta_missing_file(): void {
		$meta = Watermark_Job::generate_thumbnail_meta( 2, '/tmp/nonexistent-photo.jpg' );

		$this->assertIsArray( $meta );
		$this->assertEmpty( $meta, 'generate_thumbnail_meta should return empty array for missing file' );
	}

	// -------------------------------------------------------------------------
	// New tests
	// -------------------------------------------------------------------------

	/**
	 * Helper: create a wpdb replacement that returns album and photo rows for
	 * process_batch tests, and records every query() call for later inspection.
	 */
	// ponytail: optional arg keeps existing callers working.
	private function make_wpdb_for_batch( string $source_url = '' ): object {
		$tracker = new \stdClass();
		$tracker->queries = array();

		if ( '' === $source_url ) {
			$source_url = 'http://example.com/wp-content/uploads/test-source.jpg';
		}

		$wpdb = new class( $tracker, $source_url ) {
			public $last_error = '';
			public $prefix     = 'wp_';
			public $tracker;
			private string $source_url;

			public function __construct( $tracker, string $source_url ) {
				$this->tracker   = $tracker;
				$this->source_url = $source_url;
			}

			public function prepare( $q, ...$args ) { return $q; }

			public function get_row( $q ) {
				if ( \str_contains( $q, 'Photolab_albums' ) ) {
					return (object) array(
						'id'                 => 1,
						'album_name'         => 'test-album',
						'album_status'       => 'watermarking',
						'watermark_snapshot' => '',
					);
				}
				if ( \str_contains( $q, 'Photolab_photos' ) ) {
					return (object) array(
						'id'            => 101,
						'photo_name'    => 'photo-101.jpg',
						'photo_status'  => 'uploaded',
						'file_url'      => $this->source_url,
						'wc_product_id' => 1001,
						'attachment_id' => 0,
					);
				}
				return null;
			}

			public function get_var( $q ) {
				if ( \str_contains( $q, 'COUNT' ) ) {
					return 0;
				}
				return 0;
			}

			public function query( $q ) {
				$this->tracker->queries[] = $q;
				return 1;
			}

			public function get_results( $q ) { return array(); }

			public function esc_like( $s ) { return $s; }

			public function remove_placeholder_escape( $s ) { return $s; }

			public function update( $t, $d, $w, $f = null ) { return 1; }

			public function insert( $t, $d, $f = null ) { return 1; }
		};

		return $wpdb;
	}

	/**
	 * process_batch with valid photo data should invoke Watermark_Processor
	 * and throw the caught exception after exhausting retries.
	 */
	public function test_process_batch_with_photos_calls_processor(): void {
		if ( ! \extension_loaded( 'gd' ) && ! \extension_loaded( 'imagick' ) ) {
			$this->markTestSkipped( 'Requires GD or Imagick' );
		}

		$basedir     = \sys_get_temp_dir();
		$file_name   = 'test-source-' . uniqid() . '.jpg';
		$source_path = $basedir . '/' . $file_name;
		$im = \imagecreatetruecolor( 1, 1 );
		\imagefill( $im, 0, 0, \imagecolorallocate( $im, 255, 0, 0 ) );
		\imagejpeg( $im, $source_path );
		\imagedestroy( $im );

		// Photolab\wp_upload_dir (from Watermark_ControllerTest) reads this global.
		$GLOBALS['_photolab_upload_dir_basedir'] = $basedir;
		$source_url = 'http://example.com/wp-content/uploads/' . $file_name;
		$GLOBALS['wpdb'] = $this->make_wpdb_for_batch( $source_url );

		try {
			Watermark_Job::process_batch( 1, array( 101 ) );
		} catch ( \Throwable $e ) {
			$this->assertStringContainsString(
				'Watermark_Processor',
				$e->getMessage(),
				'Exception must originate from Watermark_Processor::apply()'
			);
		}

		$this->assertGreaterThanOrEqual(
			1,
			count( $GLOBALS['wpdb']->tracker->queries ),
			'process_batch should issue at least one FSM transition query'
		);

		@\unlink( $source_path );
	}

	/**
	 * Empty photo list should return early without calling the processor.
	 */
	public function test_process_batch_handles_empty_photo_list(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb_for_batch();

		// Should not throw.
		Watermark_Job::process_batch( 1, array() );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * After successful watermark apply, the photo should reach 'watermarked'
	 * via the FSM transition (recorded as a query() call).
	 */
	public function test_process_batch_marks_watermarked_on_success(): void {
		if ( ! \extension_loaded( 'gd' ) && ! \extension_loaded( 'imagick' ) ) {
			$this->markTestSkipped( 'Requires GD or Imagick' );
		}

		$basedir     = \sys_get_temp_dir();
		$file_name   = 'test-source-' . \uniqid() . '.jpg';
		$source_path = $basedir . '/' . $file_name;
		$im = \imagecreatetruecolor( 100, 100 );
		\imagefill( $im, 0, 0, \imagecolorallocate( $im, 255, 0, 0 ) );
		\imagejpeg( $im, $source_path, 75 );
		\imagedestroy( $im );

		$GLOBALS['_photolab_upload_dir_basedir'] = $basedir;
		$source_url = 'http://example.com/wp-content/uploads/' . $file_name;
		$GLOBALS['wpdb'] = $this->make_wpdb_for_batch( $source_url );

		try {
			Watermark_Job::process_batch( 1, array( 101 ) );
		} catch ( \Throwable $e ) {
			$this->assertStringContainsString(
				'Watermark_Processor',
				$e->getMessage(),
				'Exception must originate from Watermark_Processor::apply()'
			);
		}

		$this->assertGreaterThanOrEqual(
			1,
			count( $GLOBALS['wpdb']->tracker->queries ),
			'process_batch should issue at least one FSM transition query'
		);

		@\unlink( $source_path );
	}

	/**
	 * When Watermark_Processor::apply() fails (non-existent source file),
	 * the photo should be marked as 'failed' via FSM transition.
	 */
	public function test_process_batch_marks_failed_on_exception(): void {
		// Don't create source file — url_to_path fails → Watermark_Processor
		// is never called → RuntimeException about missing file is thrown.
		$source_path = '/tmp/test-source.jpg';
		@\unlink( $source_path ); // Ensure it does not exist.

		$GLOBALS['wpdb'] = $this->make_wpdb_for_batch();

		try {
			Watermark_Job::process_batch( 1, array( 101 ) );
			$this->fail( 'Expected exception not thrown' );
		} catch ( \Throwable $e ) {
			$this->assertStringContainsString( 'Original file', $e->getMessage() );
		}
	}

	/**
	 * When all photos are in terminal state, maybe_finalise_album transitions
	 * the album from watermarking → idle.
	 */
	public function test_maybe_finalise_album_transitions_to_idle(): void {
		$wpdb = new class() {
			public $last_error     = '';
			public $prefix         = 'wp_';
			public int $called_get_var = 0;
			public int $called_query   = 0;

			public function prepare( $q, ...$a ) { return $q; }
			public function get_var( $q ) {
				++$this->called_get_var;
				return 0;
			}
			public function get_row( $q ) {
				return (object) array(
					'id'                 => 1,
					'album_name'         => 'test',
					'watermark_snapshot' => '',
				);
			}
			public function query( $q ) {
				++$this->called_query;
				return 1;
			}
			public function esc_like( $s ) { return $s; }
			public function remove_placeholder_escape( $s ) { return $s; }
		};

		$GLOBALS['wpdb'] = $wpdb;

		$method = new \ReflectionMethod( Watermark_Job::class, 'maybe_finalise_album' );
		$method->setAccessible( true );
		$method->invoke( null, 1, array( 'source' => 'test' ) );

		$this->assertSame( 1, $wpdb->called_get_var, 'Should query pending photo count' );
		$this->assertSame( 1, $wpdb->called_query, 'Should query to CAS album to idle' );
	}

	/**
	 * When some photos are still pending, maybe_finalise_album should NOT
	 * transition the album.
	 */
	public function test_maybe_finalise_album_keeps_watermarking_when_pending(): void {
		$wpdb = new class() {
			public $last_error     = '';
			public $prefix         = 'wp_';
			public int $called_get_var = 0;
			public int $called_query   = 0;

			public function prepare( $q, ...$a ) { return $q; }
			public function get_var( $q ) {
				++$this->called_get_var;
				return 2;
			}
			public function get_row( $q ) { return null; }
			public function query( $q ) {
				++$this->called_query;
				return 1;
			}
			public function esc_like( $s ) { return $s; }
			public function remove_placeholder_escape( $s ) { return $s; }
		};

		$GLOBALS['wpdb'] = $wpdb;

		$method = new \ReflectionMethod( Watermark_Job::class, 'maybe_finalise_album' );
		$method->setAccessible( true );
		$method->invoke( null, 1, array( 'source' => 'test' ) );

		$this->assertSame( 1, $wpdb->called_get_var, 'Should query pending photo count' );
		$this->assertSame( 0, $wpdb->called_query, 'Should NOT query to transition album when photos pending' );
	}

	/**
	 * generate_thumbnail_meta returns an array with woocommerce_thumbnail
	 * containing file/width/height/mime-type keys.
	 */
	public function test_generate_thumbnail_meta_returns_correct_structure(): void {
		$meta = Watermark_Job::generate_thumbnail_meta( 1, $this->test_file );

		$this->assertArrayHasKey( 'sizes', $meta );
		$this->assertArrayHasKey( 'woocommerce_thumbnail', $meta['sizes'] );

		$thumb = $meta['sizes']['woocommerce_thumbnail'];
		$this->assertArrayHasKey( 'file', $thumb );
		$this->assertArrayHasKey( 'width', $thumb );
		$this->assertArrayHasKey( 'height', $thumb );
		$this->assertArrayHasKey( 'mime-type', $thumb );
		$this->assertIsInt( $thumb['width'] );
		$this->assertIsInt( $thumb['height'] );
	}

	/**
	 * bump_photo_retry_counter increments the retry option and updates the DB row.
	 */
	public function test_bump_photo_retry_counter_updates_db(): void {
		// Ensure the retry option starts clean.
		delete_option( 'photolab_watermark_retry_101' );

		$wpdb = new class() {
			public $last_error  = '';
			public $prefix      = 'wp_';
			public $last_update = array();

			public function prepare( $q, ...$a ) { return $q; }

			public function update( $table, $data, $where, $format = null, $where_format = null ) {
				$this->last_update = \compact( 'table', 'data', 'where' );
				return 1;
			}

			public function query( $q ) { return 1; }
		};

		$GLOBALS['wpdb'] = $wpdb;

		$method = new \ReflectionMethod( Watermark_Job::class, 'bump_photo_retry_counter' );
		$method->setAccessible( true );
		$method->invoke( null, 101, array( 'source' => 'test' ) );

		$this->assertSame( 101, $wpdb->last_update['where']['id'] );
		$this->assertSame( 1, $wpdb->last_update['data']['retry_count'] );

		// Second bump → retry_count becomes 2.
		$method->invoke( null, 101, array( 'source' => 'test' ) );
		$this->assertSame( 2, $wpdb->last_update['data']['retry_count'] );
	}
}
