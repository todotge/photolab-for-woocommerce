<?php
namespace Photolab\Tests\Integration;

use WP_UnitTestCase;

class StateMachineIntegrationTest extends WP_UnitTestCase {
	private \Photolab\State_Machine $fsm;

	public function setUp(): void {
		parent::setUp();
		$this->fsm = new \Photolab\State_Machine();
	}

	private function insert_album( string $name = 'test-album', string $status = 'idle' ): int {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'Photolab_albums', [
			'album_name' => $name,
			'status' => $status,
		] );
		return $wpdb->insert_id;
	}

	private function insert_photo( int $album_id, string $status = 'uploaded' ): int {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'Photolab_photos', [
			'album_id' => $album_id,
			'album_name' => 'test-photo',
			'photo_name' => 'photo-' . uniqid(),
			'photo_price' => 10.00,
			'file_url' => 'http://example.com/photo.jpg',
			'file_hash' => md5( uniqid() . microtime() ),
			'photo_status' => $status,
		] );
		return $wpdb->insert_id;
	}

	public function test_transition_album_updates_db(): void {
		$id = $this->insert_album( 'state-album', 'idle' );
		$result = $this->fsm->transition_album( $id, 'idle', 'uploading' );
		$this->assertTrue( $result );

		global $wpdb;
		$status = $wpdb->get_var( $wpdb->prepare(
			"SELECT status FROM {$wpdb->prefix}Photolab_albums WHERE id = %d",
			$id
		) );
		$this->assertEquals( 'uploading', $status );
	}

	public function test_transition_album_cas_miss_does_not_change(): void {
		$id = $this->insert_album( 'cas-album', 'uploading' );
		$result = $this->fsm->transition_album( $id, 'idle', 'uploading' );
		$this->assertFalse( $result );

		global $wpdb;
		$status = $wpdb->get_var( $wpdb->prepare(
			"SELECT status FROM {$wpdb->prefix}Photolab_albums WHERE id = %d",
			$id
		) );
		$this->assertEquals( 'uploading', $status, 'CAS miss should not change status' );
	}

	public function test_transition_photo_updates_db(): void {
		$album_id = $this->insert_album( 'photo-album', 'watermarking' );
		$photo_id = $this->insert_photo( $album_id, 'uploaded' );
		$result = $this->fsm->transition_photo( $photo_id, 'uploaded', 'watermarking' );
		$this->assertTrue( $result );

		global $wpdb;
		$status = $wpdb->get_var( $wpdb->prepare(
			"SELECT photo_status FROM {$wpdb->prefix}Photolab_photos WHERE id = %d",
			$photo_id
		) );
		$this->assertEquals( 'watermarking', $status );
	}

	public function test_get_album_returns_row(): void {
		$id = $this->insert_album( 'get-album', 'idle' );
		$row = $this->fsm->get_album( $id );
		$this->assertNotNull( $row );
		$this->assertEquals( 'get-album', $row->album_name );
	}

	public function test_get_photo_returns_row(): void {
		$album_id = $this->insert_album( 'get-photo-album', 'idle' );
		$photo_id = $this->insert_photo( $album_id, 'uploaded' );
		$row = $this->fsm->get_photo( $photo_id );
		$this->assertNotNull( $row );
		$this->assertEquals( $album_id, (int) $row->album_id );
	}
}
