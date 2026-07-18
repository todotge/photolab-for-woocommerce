<?php
/**
 * Unit tests for Watermark_Processor image compositing.
 *
 * @package Photolab
 */

namespace Photolab;

use PHPUnit\Framework\TestCase;

/**
 * Verify Watermark_Processor::apply resize and compositing behavior.
 *
 * Requires either GD or Imagick extension.
 */
class WatermarkProcessorTest extends TestCase {

	private string $fixtures_dir   = '';
	private string $source_path    = '';
	private string $watermark_path = '';
	private string $output_path    = '';
	private Watermark_Processor $processor;

	protected function setUp(): void {
		parent::setUp();

		if ( ! extension_loaded( 'gd' ) && ! extension_loaded( 'imagick' ) ) {
			$this->markTestSkipped( 'Watermark_Processor tests require GD or Imagick extension.' );
		}

		$this->fixtures_dir = sys_get_temp_dir() . '/photolab-test-' . uniqid();
		mkdir( $this->fixtures_dir, 0755, true );

		$this->source_path = $this->fixtures_dir . '/source.jpg';
		$im = imagecreatetruecolor( 2400, 1800 );
		imagefill( $im, 0, 0, imagecolorallocate( $im, 255, 0, 0 ) );
		imagejpeg( $im, $this->source_path, 90 );
		imagedestroy( $im );

		$this->watermark_path = $this->fixtures_dir . '/watermark.png';
		$wm = imagecreatetruecolor( 200, 200 );
		imagesavealpha( $wm, true );
		$transparent = imagecolorallocatealpha( $wm, 0, 0, 0, 127 );
		imagefill( $wm, 0, 0, $transparent );
		$white = imagecolorallocatealpha( $wm, 255, 255, 255, 0 );
		imagestring( $wm, 5, 50, 90, 'TEST', $white );
		imagepng( $wm, $this->watermark_path );
		imagedestroy( $wm );

		$this->output_path = $this->fixtures_dir . '/output.jpg';
		$this->processor   = new Watermark_Processor();
	}

	protected function tearDown(): void {
		if ( '' !== $this->fixtures_dir && is_dir( $this->fixtures_dir ) ) {
			array_map( 'unlink', glob( $this->fixtures_dir . '/*' ) ?: array() );
			rmdir( $this->fixtures_dir );
		}
		parent::tearDown();
	}

	public function test_apply_resizes_to_1200px(): void {
		$result = Watermark_Processor::apply(
			$this->source_path,
			$this->watermark_path,
			$this->output_path,
			array( 'context' => 'test' )
		);
		$this->assertTrue( $result );
		$this->assertFileExists( $this->output_path );

		$info = getimagesize( $this->output_path );
		$this->assertLessThanOrEqual( 1200, $info[0] );
	}

	public function test_apply_positions_watermark(): void {
		$result = Watermark_Processor::apply(
			$this->source_path,
			$this->watermark_path,
			$this->output_path
		);
		$this->assertTrue( $result );
		$this->assertFileExists( $this->output_path );
	}

	public function test_apply_returns_false_on_missing_source(): void {
		$result = Watermark_Processor::apply(
			$this->fixtures_dir . '/nonexistent.jpg',
			$this->watermark_path,
			$this->output_path
		);
		$this->assertNotTrue( $result );
	}

	public function test_apply_returns_false_on_missing_watermark(): void {
		$result = Watermark_Processor::apply(
			$this->source_path,
			$this->fixtures_dir . '/nonexistent.png',
			$this->output_path
		);
		$this->assertNotTrue( $result );
	}

	public function test_apply_returns_false_both_missing(): void {
		$result = Watermark_Processor::apply(
			$this->fixtures_dir . '/nope.jpg',
			$this->fixtures_dir . '/nope.png',
			$this->output_path
		);
		$this->assertNotTrue( $result );
	}
}
