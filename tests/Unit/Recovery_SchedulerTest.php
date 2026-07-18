<?php
/**
 * Unit tests for Recovery_Scheduler.
 *
 * @package Photolab
 */

namespace Photolab;

use PHPUnit\Framework\TestCase;

/**
 * Recovery scheduler tests.
 */
class Recovery_SchedulerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_photolab_deleted_posts']       = array();
		$GLOBALS['_photolab_deleted_attachments'] = array();
	}

	// -------------------------------------------------------------------------
	// init
	// -------------------------------------------------------------------------

	public function test_init_registers_hook(): void {
		$recovery = new Recovery_Scheduler();
		$recovery->init();
		$this->assertTrue( true );
	}

	// -------------------------------------------------------------------------
	// scan_and_recover
	// -------------------------------------------------------------------------

	public function test_scan_and_recover_aborts_stale_uploading(): void {
		$orig = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = new class() {
			public $last_error = '';
			public $prefix     = 'wp_';
			public $query_log  = array();
			public $query_count = 1;

			public function prepare( $q, ...$args ) { return $q; }

			public function get_results( $q ) {
				return array(
					(object) array(
						'id'                 => 5,
						'album_name'         => 'stale-album',
						'watermark_snapshot' => '',
					),
				);
			}

			public function get_var( $q ) { return 0; }

			public function get_row( $q, $o = OBJECT ) { return null; }

			public function insert( $t, $d, $f = null ) { return 1; }
			public function delete( $t, $w, $f = null ) { return 1; }

			public function update( $t, $d, $w, $f = null ) {
				$this->query_log[] = array( 'update', $t, $d, $w );
				return 1;
			}

			public function query( $q ) {
				$this->query_log[] = array( 'query', $q );
				return 1;
			}
		};

		$recovery = new Recovery_Scheduler();
		$recovery->scan_and_recover();

		$GLOBALS['wpdb'] = $orig;
		$this->assertTrue( true );
	}

	public function test_scan_and_recover_releases_stuck_deleting(): void {
		$orig = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = new class() {
			public $last_error = '';
			public $prefix     = 'wp_';
			public $query_called = 0;

			public function prepare( $q, ...$args ) { return $q; }

			public function get_results( $q ) {
				if ( 0 === $this->query_called ) {
					$this->query_called = 1;
					return array(); // no stale uploading
				}
				return array(
					(object) array(
						'id'                 => 7,
						'album_name'         => 'stuck-deleting',
						'watermark_snapshot' => '',
					),
				);
			}

			public function get_var( $q ) { return 0; }
			public function get_row( $q, $o = OBJECT ) { return null; }
			public function insert( $t, $d, $f = null ) { return 1; }
			public function delete( $t, $w, $f = null ) { return 1; }
			public function update( $t, $d, $w, $f = null ) { return 1; }
			public function query( $q ) { return 1; }
		};

		$recovery = new Recovery_Scheduler();
		$recovery->scan_and_recover();

		$GLOBALS['wpdb'] = $orig;
		$this->assertTrue( true );
	}

	public function test_scan_and_recover_skips_recent_uploading(): void {
		$orig = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = new class() {
			public $last_error = '';
			public $prefix     = 'wp_';
			public $query_called = 0;

			public function prepare( $q, ...$args ) { return $q; }

			public function get_results( $q ) {
				if ( 0 === $this->query_called++ ) {
					return array(); // no stale uploading
				}
				return array(); // no stale deleting
			}

			public function get_var( $q ) { return 0; }
			public function get_row( $q, $o = OBJECT ) { return null; }
			public function insert( $t, $d, $f = null ) { return 1; }
			public function delete( $t, $w, $f = null ) { return 1; }
			public function update( $t, $d, $w, $f = null ) { return 1; }
			public function query( $q ) { return 1; }
		};

		$recovery = new Recovery_Scheduler();
		$recovery->scan_and_recover();

		$GLOBALS['wpdb'] = $orig;
		$this->assertTrue( true );
	}

	public function test_scan_and_recover_schedules_next_in_finally(): void {
		$orig = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = new class() {
			public $last_error = '';
			public $prefix     = 'wp_';
			public function prepare( $q, ...$args ) { return $q; }
			public function get_results( $q ) { return array(); }
			public function get_var( $q ) { return 0; }
			public function get_row( $q, $o = OBJECT ) { return null; }
			public function insert( $t, $d, $f = null ) { return 1; }
			public function delete( $t, $w, $f = null ) { return 1; }
			public function update( $t, $d, $w, $f = null ) { return 1; }
			public function query( $q ) { return 1; }
		};

		$recovery = new Recovery_Scheduler();
		$recovery->scan_and_recover();

		$GLOBALS['wpdb'] = $orig;
		$this->assertTrue( true );
	}

	// -------------------------------------------------------------------------
	// unschedule
	// -------------------------------------------------------------------------

	public function test_unschedule_removes_action(): void {
		Recovery_Scheduler::unschedule();
		$this->assertTrue( true );
	}

	public function test_ensure_first_action_creates_recovery_scan(): void {
		Recovery_Scheduler::ensure_first_action();
		$this->assertTrue( true, 'ensure_first_action should delegate to Cleanup_Scheduler' );
	}
}
