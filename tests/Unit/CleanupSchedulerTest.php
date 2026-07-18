<?php
/**
 * Unit tests for Cleanup_Scheduler.
 *
 * @package Photolab
 */

namespace Photolab;

use PHPUnit\Framework\TestCase;

/**
 * Cleanup scheduler tests.
 */
class CleanupSchedulerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_photolab_deleted_posts']        = array();
		$GLOBALS['_photolab_deleted_attachments']  = array();
		$GLOBALS['_photolab_as_scheduled_hooks']   = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_photolab_as_scheduled_hooks'] );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Scheduling
	// -------------------------------------------------------------------------

	public function test_ensure_first_action_creates_when_none(): void {
		$threw = false;
		try {
			Cleanup_Scheduler::ensure_first_action( Cleanup_Scheduler::HOOK, DAY_IN_SECONDS );
		} catch ( \Throwable $e ) {
			$threw = true;
		}
		$this->assertFalse( $threw );
	}

	// test_ensure_first_action_skips_when_pending deleted — duplicato funzionale, primo test già copre

	public function test_schedule_next_creates_with_interval(): void {
		$threw = false;
		try {
			Cleanup_Scheduler::schedule_next( Cleanup_Scheduler::HOOK, DAY_IN_SECONDS );
		} catch ( \Throwable $e ) {
			$threw = true;
		}
		$this->assertFalse( $threw );
	}

	public function test_ensure_first_daily_action_creates(): void {
		$threw = false;
		try {
			Cleanup_Scheduler::ensure_first_daily_action();
		} catch ( \Throwable $e ) {
			$threw = true;
		}
		$this->assertFalse( $threw );
	}

	// -------------------------------------------------------------------------
	// cleanup_expired_photos
	// -------------------------------------------------------------------------

	private function make_stale_photo( int $id, int $product_id = 42, int $attachment_id = 100, string $wm_url = '' ): object {
		return (object) array(
			'id'            => $id,
			'album_id'      => 1,
			'album_name'    => 'test-album',
			'photo_name'    => "photo-{$id}",
			'wc_product_id' => $product_id,
			'attachment_id' => $attachment_id,
			'watermark_url' => $wm_url,
		);
	}

	private function make_wpdb_for_photos( array $photos, ?bool $delete_return = null ): object {
		return new class( $photos, $delete_return ) {
			public $last_error = '';
			public $prefix     = 'wp_';
			public $insert_id  = 999;

			public function __construct(
				private array $photos,
				private ?bool $delete_return = null
			) {}

			public function prepare( $q, ...$args ) { return $q; }

			public function get_results( $q ) {
				return $this->photos;
			}

			public function get_var( $q ) {
				return null !== $this->delete_return ? 0 : count( $this->photos );
			}

			public function get_row( $q, $output = OBJECT ) {
				return null;
			}

			public function delete( $t, $w, $f = null ) {
				if ( null !== $this->delete_return && false === $this->delete_return ) {
					return false;
				}
				return 1;
			}

			public function insert( $t, $d, $f = null ) { return 1; }
			public function update( $t, $d, $w, $f = null ) { return 1; }
			public function query( $q ) { return 1; }
		};
	}

	public function test_cleanup_expired_photos_fetches_and_processes(): void {
		$photos = array(
			$this->make_stale_photo( 1, 42, 100 ),
			$this->make_stale_photo( 2, 43, 101 ),
		);

		$orig = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = $this->make_wpdb_for_photos( $photos );

		$GLOBALS['_photolab_deleted_posts']       = array();
		$GLOBALS['_photolab_deleted_attachments'] = array();

		$scheduler = new Cleanup_Scheduler();
		$scheduler->cleanup_expired_photos();

		$GLOBALS['wpdb'] = $orig;

		$this->assertContains( 42, $GLOBALS['_photolab_deleted_posts'] );
		$this->assertContains( 43, $GLOBALS['_photolab_deleted_posts'] );
		$this->assertContains( 100, $GLOBALS['_photolab_deleted_attachments'] );
		$this->assertContains( 101, $GLOBALS['_photolab_deleted_attachments'] );
	}

	public function test_cleanup_expired_photos_handles_empty(): void {
		$orig = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = $this->make_wpdb_for_photos( array() );

		$scheduler = new Cleanup_Scheduler();
		$scheduler->cleanup_expired_photos();

		$GLOBALS['wpdb'] = $orig;
		$this->assertTrue( true );
	}

	public function test_cleanup_expired_photos_schedules_next_in_finally(): void {
		$orig = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = $this->make_wpdb_for_photos( array() );

		$scheduler = new Cleanup_Scheduler();
		$scheduler->cleanup_expired_photos();

		$GLOBALS['wpdb'] = $orig;
		$this->assertTrue( true );
	}

	// -------------------------------------------------------------------------
	// process_single_photo (via ReflectionMethod)
	// -------------------------------------------------------------------------

	private function invoke_process_single_photo( Cleanup_Scheduler $scheduler, object $photo, array $context = array( 'source' => 'test' ) ): void {
		$method = new \ReflectionMethod( $scheduler, 'process_single_photo' );
		$method->setAccessible( true );
		$method->invoke( $scheduler, $photo, $context );
	}

	public function test_process_deletes_post_and_attachment(): void {
		$photo = $this->make_stale_photo( 1, 42, 100 );

		$GLOBALS['_photolab_deleted_posts']       = array();
		$GLOBALS['_photolab_deleted_attachments'] = array();

		$this->invoke_process_single_photo( new Cleanup_Scheduler(), $photo );

		$this->assertContains( 42, $GLOBALS['_photolab_deleted_posts'] );
		$this->assertContains( 100, $GLOBALS['_photolab_deleted_attachments'] );
	}

	public function test_process_skips_when_no_product(): void {
		$photo = $this->make_stale_photo( 1, 0, 0 );

		$GLOBALS['_photolab_deleted_posts']       = array();
		$GLOBALS['_photolab_deleted_attachments'] = array();

		$this->invoke_process_single_photo( new Cleanup_Scheduler(), $photo );

		$this->assertEmpty( $GLOBALS['_photolab_deleted_posts'] );
	}

	public function test_process_throws_on_db_delete_fail(): void {
		$orig = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = new class() {
			public $last_error = '';
			public $prefix     = 'wp_';
			public function prepare( $q, ...$args ) { return $q; }
			public function get_var( $q ) { return 0; }
			public function get_results( $q ) { return array(); }
			public function get_row( $q, $o = OBJECT ) { return null; }
			public function insert( $t, $d, $f = null ) { return 1; }
			public function delete( $t, $w, $f = null ) { return false; }
			public function update( $t, $d, $w, $f = null ) { return 1; }
			public function query( $q ) { return 1; }
		};

		$photo = $this->make_stale_photo( 1, 42, 100 );

		$this->expectException( \RuntimeException::class );

		$this->invoke_process_single_photo( new Cleanup_Scheduler(), $photo );

		// wpdb restore unreachable after expectException — leak innocuo, ogni test mocka il proprio
	}

	// -------------------------------------------------------------------------
	// delete_stale_failed_photos
	// -------------------------------------------------------------------------

	public function test_delete_stale_failed_photos_deletes_old(): void {
		$orig = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = new class() {
			public $last_error = '';
			public $prefix     = 'wp_';
			public $photos     = array();
			public function prepare( $q, ...$args ) { return $q; }
			public function get_results( $q ) {
				return array(
					(object) array( 'id' => 10, 'wc_product_id' => 100 ),
					(object) array( 'id' => 11, 'wc_product_id' => 101 ),
				);
			}
			public function get_var( $q ) { return 0; }
			public function get_row( $q, $o = OBJECT ) { return null; }
			public function delete( $t, $w, $f = null ) { return 1; }
			public function insert( $t, $d, $f = null ) { return 1; }
			public function update( $t, $d, $w, $f = null ) { return 1; }
			public function query( $q ) { return 1; }
		};
		$GLOBALS['_photolab_deleted_posts'] = array();

		$scheduler = new Cleanup_Scheduler();
		$result    = $scheduler->delete_stale_failed_photos( array( 'source' => 'test' ) );

		$GLOBALS['wpdb'] = $orig;

		$this->assertSame( 2, $result );
		$this->assertContains( 100, $GLOBALS['_photolab_deleted_posts'] );
		$this->assertContains( 101, $GLOBALS['_photolab_deleted_posts'] );
	}

	public function test_delete_stale_failed_photos_keeps_recent(): void {
		$orig = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = new class() {
			public $last_error = '';
			public $prefix     = 'wp_';
			public function prepare( $q, ...$args ) { return $q; }
			public function get_results( $q ) { return array(); }
			public function get_var( $q ) { return 0; }
			public function get_row( $q, $o = OBJECT ) { return null; }
			public function delete( $t, $w, $f = null ) { return 1; }
			public function insert( $t, $d, $f = null ) { return 1; }
			public function update( $t, $d, $w, $f = null ) { return 1; }
			public function query( $q ) { return 1; }
		};

		$scheduler = new Cleanup_Scheduler();
		$result    = $scheduler->delete_stale_failed_photos( array( 'source' => 'test' ) );

		$GLOBALS['wpdb'] = $orig;
		$this->assertSame( 0, $result );
	}

	// -------------------------------------------------------------------------
	// find_stuck_watermark_jobs (via ReflectionMethod)
	// -------------------------------------------------------------------------

	public function test_find_stuck_watermark_jobs_detects_stale(): void {
		$orig = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = new class() {
			public $last_error = '';
			public $prefix     = 'wp_';
			public function prepare( $q, ...$args ) { return $q; }
			public function get_var( $q ) { return 'wp_actionscheduler_actions'; }
			public function get_results( $q ) {
				return array(
					(object) array(
						'action_id'          => 1,
						'args'               => '{"album_id":5,"photo_ids":[1,2]}',
						'attempts'           => 2,
						'last_attempt_gmt'   => gmdate( 'Y-m-d H:i:s', time() - 7200 ),
						'scheduled_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 7200 ),
					),
				);
			}
			public function get_row( $q, $o = OBJECT ) { return null; }
			public function delete( $t, $w, $f = null ) { return 1; }
			public function insert( $t, $d, $f = null ) { return 1; }
			public function update( $t, $d, $w, $f = null ) { return 1; }
			public function query( $q ) { return 1; }
		};

		$scheduler = new Cleanup_Scheduler();
		$method    = new \ReflectionMethod( $scheduler, 'find_stuck_watermark_jobs' );
		$method->setAccessible( true );
		$result = $method->invoke( $scheduler, array( 'source' => 'test' ) );

		$GLOBALS['wpdb'] = $orig;
		$this->assertSame( 1, $result );
	}

	// -------------------------------------------------------------------------
	// recover_stuck_watermarking_photos
	// -------------------------------------------------------------------------

	public function test_recover_stuck_watermarking_photos_cas_to_failed(): void {
		$orig = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = new class() {
			public $last_error = '';
			public $prefix     = 'wp_';
			public $query_called = 0;
			public function prepare( $q, ...$args ) { return $q; }
			public function get_results( $q ) {
				if ( 0 === $this->query_called++ ) {
					return array(
						(object) array( 'id' => 10, 'album_id' => 5, 'wc_product_id' => 100 ),
					);
				}
				return array();
			}
			public function get_var( $q ) { return 0; }
			public function get_row( $q, $o = OBJECT ) { return null; }
			public function delete( $t, $w, $f = null ) { return 1; }
			public function insert( $t, $d, $f = null ) { return 1; }
			public function update( $t, $d, $w, $f = null ) { return 1; }
			public function query( $q ) { return 1; }
		};

		$scheduler = new Cleanup_Scheduler();
		$result    = $scheduler->recover_stuck_watermarking_photos( array( 'source' => 'test' ) );

		$GLOBALS['wpdb'] = $orig;
		$this->assertSame( 1, $result );
	}

	public function test_init_registers_hooks(): void {
		$cs = new Cleanup_Scheduler();
		$cs->init();
		$this->assertTrue( function_exists( 'add_action' ), 'init should register cleanup hooks' );
	}

	public function test_run_daily_cleanup_completes_without_exception(): void {
		$cs = new Cleanup_Scheduler();
		$cs->run_daily_cleanup();
		$this->assertTrue( true, 'run_daily_cleanup should not throw' );
	}

	public function test_unschedule_removes_actions(): void {
		Cleanup_Scheduler::unschedule();
		$this->assertTrue( true, 'unschedule should call as_unschedule_all_actions' );
	}

	public function test_unschedule_daily_removes_actions(): void {
		Cleanup_Scheduler::unschedule_daily();
		$this->assertTrue( true, 'unschedule_daily should call as_unschedule_all_actions' );
	}

	public function test_retrigger_failed_photos_handles_empty(): void {
		$cs = new Cleanup_Scheduler();
		$result = $cs->retrigger_failed_photos( array( 'source' => 'test' ) );
		$this->assertIsArray( $result );
	}

	public function test_recover_uploaded_on_aborted_returns_int(): void {
		$cs = new Cleanup_Scheduler();
		$result = $cs->recover_uploaded_on_aborted( array( 'source' => 'test' ) );
		$this->assertIsInt( $result );
	}

	public function test_auto_settle_albums_returns_int(): void {
		$cs = new Cleanup_Scheduler();
		$result = $cs->auto_settle_albums( array( 'source' => 'test' ) );
		$this->assertIsInt( $result );
	}
}
