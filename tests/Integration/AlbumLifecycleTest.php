<?php
namespace Photolab\Tests\Integration;

use WP_UnitTestCase;

class AlbumLifecycleTest extends WP_UnitTestCase {

	public function test_full_album_flow(): void {
		global $wpdb;
		$photos_table = $wpdb->prefix . 'Photolab_photos';
		$albums_table = $wpdb->prefix . 'Photolab_albums';

		$album_name = 'test-flow-' . uniqid();
		$wpdb->insert( $albums_table, [
			'album_name' => $album_name,
			'status' => 'idle',
			'created_at' => current_time( 'mysql' ),
		] );
		$album_id = $wpdb->insert_id;
		$this->assertGreaterThan( 0, $album_id );

		$album = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$albums_table} WHERE id = %d", $album_id
		) );
		$this->assertEquals( 'idle', $album->status );

		$photo_name = 'photo-' . uniqid();
		$wpdb->insert( $photos_table, [
			'album_id' => $album_id,
			'album_name' => $album_name,
			'photo_name' => $photo_name,
			'photo_price' => 15.00,
			'file_url' => 'http://example.com/photos/' . $photo_name . '.jpg',
			'file_hash' => md5( $photo_name ),
			'photo_status' => 'uploaded',
			'published' => 1,
		] );
		$photo_id = $wpdb->insert_id;
		$this->assertGreaterThan( 0, $photo_id );

		$photo = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$photos_table} WHERE id = %d", $photo_id
		) );
		$this->assertEquals( 'uploaded', $photo->photo_status );
		$this->assertEquals( $album_id, (int) $photo->album_id );

		$wpdb->delete( $photos_table, [ 'id' => $photo_id ] );
		$wpdb->delete( $albums_table, [ 'id' => $album_id ] );
	}

	public function test_album_with_expiration_date(): void {
		global $wpdb;
		$albums_table = $wpdb->prefix . 'Photolab_albums';

		$expiration = '2025-01-01 00:00:00';
		$wpdb->insert( $albums_table, [
			'album_name' => 'expired-album-' . uniqid(),
			'status' => 'idle',
			'expiration_date' => $expiration,
		] );
		$album_id = $wpdb->insert_id;

		$album = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$albums_table} WHERE id = %d", $album_id
		) );
		$this->assertEquals( $expiration, $album->expiration_date );

		$wpdb->delete( $albums_table, [ 'id' => $album_id ] );
	}

	public function test_expired_photos_queryable(): void {
		global $wpdb;
		$photos_table = $wpdb->prefix . 'Photolab_photos';
		$albums_table = $wpdb->prefix . 'Photolab_albums';

		$album_name = 'exp-test-' . uniqid();
		$wpdb->insert( $albums_table, [
			'album_name' => $album_name,
			'status' => 'idle',
		] );
		$album_id = $wpdb->insert_id;

		$wpdb->insert( $photos_table, [
			'album_id' => $album_id,
			'album_name' => $album_name,
			'photo_name' => 'exp-photo',
			'photo_price' => 10.00,
			'file_url' => 'http://example.com/p.jpg',
			'file_hash' => md5( uniqid() ),
			'expiration_date' => '2024-01-01 00:00:00',
			'published' => 1,
			'photo_status' => 'watermarked',
		] );

		$count = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$photos_table} p
			 WHERE p.expiration_date IS NOT NULL
			   AND p.expiration_date < %s
			   AND p.published = 1
			   AND EXISTS (
				   SELECT 1 FROM {$albums_table} a
				   WHERE a.id = p.album_id AND a.status IN ( 'idle', 'aborted' )
			   )",
			current_time( 'mysql', true )
		) );

		$this->assertGreaterThan( 0, (int) $count );

		$wpdb->delete( $photos_table, [ 'album_id' => $album_id ] );
		$wpdb->delete( $albums_table, [ 'id' => $album_id ] );
	}
}
