<?php
namespace Photolab\Tests\Integration;

use WP_UnitTestCase;

class WooCommerceIntegrationTest extends WP_UnitTestCase {
    
    public function test_wc_product_simple_is_available(): void {
        $product = new \WC_Product_Simple();
        $product->set_name( 'Test Photo' );
        $product->set_virtual( true );
        $product->set_downloadable( true );
        $product->set_regular_price( '15.00' );
        $product->set_status( 'publish' );
        $id = $product->save();
        
        $this->assertGreaterThan( 0, $id );
        
        $saved = wc_get_product( $id );
        $this->assertInstanceOf( \WC_Product_Simple::class, $saved );
        $this->assertEquals( 'Test Photo', $saved->get_name() );
        $this->assertEquals( '15.00', $saved->get_regular_price() );
        $this->assertTrue( $saved->get_virtual() );
        $this->assertTrue( $saved->get_downloadable() );
        
        $saved->delete( true );
    }
    
    public function test_wc_product_category_can_be_created_and_deleted(): void {
        $cat_name = 'test-cat-' . uniqid();
        $term = wp_insert_term( $cat_name, 'product_cat' );
        $this->assertNotWPError( $term );
        
        $term_id = $term['term_id'];
        $this->assertGreaterThan( 0, $term_id );
        
        $deleted = wp_delete_term( $term_id, 'product_cat' );
        $this->assertTrue( $deleted );
        
        $this->assertNull( term_exists( $cat_name, 'product_cat' ) );
    }
}
