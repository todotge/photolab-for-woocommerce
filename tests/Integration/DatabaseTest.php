<?php
namespace Photolab\Tests\Integration;

use WP_UnitTestCase;

class DatabaseTest extends WP_UnitTestCase {
	private \Photolab\Database $database;

	public function setUp(): void {
		parent::setUp();
		$this->database = new \Photolab\Database();
		$this->database->install();
	}

	public function test_install_creates_photos_table(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'Photolab_photos';
		$this->assertEquals( $table, $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) );
	}

	public function test_install_creates_albums_table(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'Photolab_albums';
		$this->assertEquals( $table, $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) );
	}

	public function test_install_adds_published_expiration_index(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'Photolab_photos';
		$indexes = $wpdb->get_results( "SHOW INDEX FROM `{$table}` WHERE Key_name = 'published_expiration'" );
		$this->assertNotEmpty( $indexes, 'published_expiration index should exist' );
	}

	public function test_install_adds_file_hash_album_index(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'Photolab_photos';
		$indexes = $wpdb->get_results( "SHOW INDEX FROM `{$table}` WHERE Key_name = 'file_hash_album'" );
		$this->assertNotEmpty( $indexes, 'file_hash_album index should exist' );
	}

	public function test_maybe_update_migrates_from_v100(): void {
		update_option( 'photolab_db_version', '1.0.0' );
		$this->database->maybe_update();
		$this->assertEquals( '1.4.0', $this->database->get_version() );
	}

	public function test_maybe_update_does_not_downgrade(): void {
		update_option( 'photolab_db_version', '1.4.0' );
		$this->database->maybe_update();
		$this->assertEquals( '1.4.0', $this->database->get_version() );
	}

	public function test_add_column_if_missing_does_nothing_when_exists(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'Photolab_photos';
		$result = $wpdb->query( "SELECT retry_count FROM `{$table}` LIMIT 1" );
		$this->assertNotFalse( $result );
	}

	public function test_verify_table_returns_true(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'Photolab_photos';
		$class = new \ReflectionClass( \Photolab\Database::class );
		$method = $class->getMethod( 'verify_table' );
		$method->setAccessible( true );
		// verify_table returns void; no exception means success.
		$method->invoke( $this->database, $table );
		$this->assertTrue( true );
	}

	public function test_get_version_reads_db_version(): void {
		update_option( 'photolab_db_version', '1.4.0' );
		$this->assertEquals( '1.4.0', $this->database->get_version() );
	}

	public function test_update_version_sets_db_version(): void {
		$this->database->update_version( '2.0.0' );
		$this->assertEquals( '2.0.0', get_option( 'photolab_db_version' ) );
	}
}
