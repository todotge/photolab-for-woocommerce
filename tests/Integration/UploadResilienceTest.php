<?php
namespace Photolab\Tests\Integration;

use WP_UnitTestCase;

class UploadResilienceTest extends WP_UnitTestCase {
    private string $albums_table;
    
    public function setUp(): void {
        parent::setUp();
        global $wpdb;
        $this->albums_table = $wpdb->prefix . 'Photolab_albums';
    }
    
    public function test_aborted_album_can_be_reset(): void {
        global $wpdb;
        $album_name = 'reset-test-' . uniqid();
        $wpdb->insert( $this->albums_table, [
            'album_name' => $album_name,
            'status' => 'aborted',
        ] );
        $album_id = $wpdb->insert_id;
        
        $controller = new \Photolab\Album_Controller();
        $request = new \WP_REST_Request( 'POST', "/photolab/v1/albums/{$album_id}/reset" );
        $request->set_param( 'id', $album_id );
        $response = $controller->reset_item( $request );
        
        $this->assertEquals( 200, $response->get_status() );
        
        $status = $wpdb->get_var( $wpdb->prepare(
            "SELECT status FROM {$this->albums_table} WHERE id = %d", $album_id
        ) );
		$this->assertEquals( 'idle', $status, 'Aborted album should reset to idle' );
        
        $wpdb->delete( $this->albums_table, [ 'id' => $album_id ] );
    }
}
