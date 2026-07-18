<?php
namespace Photolab\Tests\Integration;

use WP_UnitTestCase;

class RecoveryScanTest extends WP_UnitTestCase {
    private string $albums_table;
    private string $photos_table;
    
    public function setUp(): void {
        parent::setUp();
        global $wpdb;
        $this->albums_table = $wpdb->prefix . 'Photolab_albums';
        $this->photos_table = $wpdb->prefix . 'Photolab_photos';
    }
    
    public function test_aborts_and_settles_stale_uploading_album(): void {
        global $wpdb;
        $album_name = 'stale-upload-' . uniqid();
        $wpdb->insert( $this->albums_table, [
            'album_name' => $album_name,
            'status' => 'uploading',
            'last_heartbeat' => gmdate( 'Y-m-d H:i:s', time() - 900 ),
            'upload_started_at' => gmdate( 'Y-m-d H:i:s', time() - 1800 ),
        ] );
        $album_id = $wpdb->insert_id;
        
        $recovery = new \Photolab\Recovery_Scheduler();
        $recovery->scan_and_recover();
        
        $status = $wpdb->get_var( $wpdb->prepare(
            "SELECT status FROM {$this->albums_table} WHERE id = %d", $album_id
        ) );
        // Recovery aborts (uploading→aborted), then auto_settle_albums
        // settles to idle (aborted+0 photos→idle).
        $this->assertEquals( 'idle', $status );
    }
    
    public function test_skips_recent_uploading_album(): void {
        global $wpdb;
        $album_name = 'recent-upload-' . uniqid();
        $wpdb->insert( $this->albums_table, [
            'album_name' => $album_name,
            'status' => 'uploading',
            'last_heartbeat' => gmdate( 'Y-m-d H:i:s', time() - 60 ),
        ] );
        $album_id = $wpdb->insert_id;
        
        $recovery = new \Photolab\Recovery_Scheduler();
        $recovery->scan_and_recover();
        
        $status = $wpdb->get_var( $wpdb->prepare(
            "SELECT status FROM {$this->albums_table} WHERE id = %d", $album_id
        ) );
        $this->assertEquals( 'uploading', $status, 'Recent album should not be aborted' );
        
        $wpdb->delete( $this->albums_table, [ 'id' => $album_id ] );
    }
}
