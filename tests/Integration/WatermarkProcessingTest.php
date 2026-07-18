<?php
namespace Photolab\Tests\Integration;

use WP_UnitTestCase;

class WatermarkProcessingTest extends WP_UnitTestCase {
    private string $fixtures_dir;
    private string $source_path;
    private string $watermark_path;
    private string $output_path;
    
    public function setUp(): void {
        parent::setUp();
        $this->fixtures_dir = sys_get_temp_dir() . '/photolab-int-test-' . uniqid();
        mkdir( $this->fixtures_dir, 0755, true );
        $this->watermark_path = '/nonexistent/watermark.png';
        
        if ( extension_loaded( 'gd' ) ) {
            $im = imagecreatetruecolor( 2400, 1800 );
            imagefill( $im, 0, 0, imagecolorallocate( $im, 255, 0, 0 ) );
            $this->source_path = $this->fixtures_dir . '/source.jpg';
            imagejpeg( $im, $this->source_path, 90 );
            imagedestroy( $im );
            
            $wm = imagecreatetruecolor( 200, 200 );
            $white = imagecolorallocate( $wm, 255, 255, 255 );
            imagefill( $wm, 0, 0, $white );
            imagestring( $wm, 5, 50, 90, 'WM', 0 );
            $this->watermark_path = $this->fixtures_dir . '/watermark.png';
            imagepng( $wm, $this->watermark_path );
            imagedestroy( $wm );
        }
        
        $this->output_path = $this->fixtures_dir . '/output.jpg';
    }
    
    public function test_processor_creates_output_file(): void {
        if ( ! extension_loaded( 'gd' ) ) {
            $this->markTestSkipped( 'GD required for image processing test' );
        }
        
        $result = \Photolab\Watermark_Processor::apply(
            $this->source_path,
            $this->watermark_path,
            $this->output_path,
            [ 'context' => 'test' ]
        );
        
        $this->assertNotFalse( $result, 'Watermark_Processor should return a path on success' );
        $this->assertFileExists( $this->output_path );
        
        $size = getimagesize( $this->output_path );
        $this->assertLessThanOrEqual( 1200, $size[0], 'Output width should be ≤ 1200px' );
    }
    
    public function test_processor_returns_false_on_missing_source(): void {
        $result = \Photolab\Watermark_Processor::apply(
            '/nonexistent/source.jpg',
            $this->watermark_path,
            $this->output_path,
            [ 'context' => 'test' ]
        );
        $this->assertIsString( $result );
        $this->assertNotTrue( $result );
    }
    
    public function test_processor_returns_false_on_missing_watermark(): void {
        if ( ! extension_loaded( 'gd' ) ) {
            $this->markTestSkipped( 'GD required' );
        }
        $result = \Photolab\Watermark_Processor::apply(
            $this->source_path,
            '/nonexistent/watermark.png',
            $this->output_path,
            [ 'context' => 'test' ]
        );
        $this->assertIsString( $result );
        $this->assertNotTrue( $result );
    }
    
    public function tearDown(): void {
        parent::tearDown();
        if ( $this->fixtures_dir && is_dir( $this->fixtures_dir ) ) {
            array_map( 'unlink', glob( $this->fixtures_dir . '/*' ) ?: [] );
            rmdir( $this->fixtures_dir );
        }
    }
}
