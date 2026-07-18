<?php
namespace Photolab\Tests\Integration;

use WP_UnitTestCase;

class CleanupExpirationTest extends WP_UnitTestCase {

	public function test_cleanup_expired_photos_skips_unexpired(): void {
		global $wpdb;
		$photos_table = $wpdb->prefix . 'Photolab_photos';
		$albums_table = $wpdb->prefix . 'Photolab_albums';

		$album_name = 'future-album-' . uniqid();
		$wpdb->insert( $albums_table, [ 'album_name' => $album_name, 'status' => 'idle' ] );
		$album_id = $wpdb->insert_id;

		$wpdb->insert( $photos_table, [
			'album_id' => $album_id,
			'album_name' => $album_name,
			'photo_name' => 'future-photo',
			'photo_price' => 10.00,
			'file_url' => 'http://example.com/f.jpg',
			'file_hash' => md5( uniqid() ),
			'expiration_date' => '2099-01-01 00:00:00',
			'published' => 1,
			'photo_status' => 'watermarked',
		] );
		$photo_id = $wpdb->insert_id;

		$cleanup = new \Photolab\Cleanup_Scheduler();
		$cleanup->cleanup_expired_photos();

		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$photos_table} WHERE id = %d", $photo_id
		) );
		$this->assertEquals( 1, (int) $exists, 'Unexpired photo should not be deleted' );

		$wpdb->delete( $photos_table, [ 'album_id' => $album_id ] );
		$wpdb->delete( $albums_table, [ 'id' => $album_id ] );
	}
}
