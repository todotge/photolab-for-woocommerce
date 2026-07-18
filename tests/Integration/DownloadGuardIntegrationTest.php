<?php
namespace Photolab\Tests\Integration;

use WP_UnitTestCase;

class DownloadGuardIntegrationTest extends WP_UnitTestCase {
    
    public function test_woocommerce_filter_is_registered(): void {
        \Photolab\Download_Guard::init();
        $this->assertGreaterThan(
            0,
            has_filter( 'woocommerce_product_file_download_path', [ \Photolab\Download_Guard::class, 'filter_download_path' ] )
        );
    }
    
    public function test_filter_ignores_non_photolab_paths(): void {
        \Photolab\Download_Guard::init();
        $path = apply_filters(
            'woocommerce_product_file_download_path',
            '/var/www/wp-content/uploads/other/file.jpg',
            null,
            'download_id'
        );
        $this->assertEquals( '/var/www/wp-content/uploads/other/file.jpg', $path );
    }
}
