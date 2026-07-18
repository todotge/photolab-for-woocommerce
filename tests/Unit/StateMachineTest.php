<?php
/**
 * Unit tests for State_Machine::is_valid_transition().
 *
 * @package Photolab
 */

namespace Photolab;

use PHPUnit\Framework\TestCase;

/**
 * Verify the static state transition graph for albums and photos.
 */
class StateMachineTest extends TestCase {

	/**
	 * Every valid album transition in the graph.
	 *
	 * @see State_Machine::ALLOWED_TRANSITIONS
	 */
	public function test_valid_album_transitions(): void {
		$valid = array(
			array( State_Machine::ALBUM_IDLE, State_Machine::ALBUM_UPLOADING ),
			array( State_Machine::ALBUM_IDLE, State_Machine::ALBUM_DELETING ),
			array( State_Machine::ALBUM_UPLOADING, State_Machine::ALBUM_WATERMARKING ),
			array( State_Machine::ALBUM_UPLOADING, State_Machine::ALBUM_IDLE ),
			array( State_Machine::ALBUM_UPLOADING, State_Machine::ALBUM_ABORTED ),
			array( State_Machine::ALBUM_WATERMARKING, State_Machine::ALBUM_IDLE ),
			array( State_Machine::ALBUM_WATERMARKING, State_Machine::ALBUM_ABORTED ),
			array( State_Machine::ALBUM_ABORTED, State_Machine::ALBUM_IDLE ),
			array( State_Machine::ALBUM_ABORTED, State_Machine::ALBUM_DELETING ),
		);

		foreach ( $valid as $idx => list( $from, $to ) ) {
			$this->assertTrue(
				State_Machine::is_valid_transition( 'album', $from, $to ),
				"Album transition #{$idx} should be valid: {$from} → {$to}"
			);
		}
	}

	/**
	 * Every invalid album transition.
	 */
	public function test_invalid_album_transitions(): void {
		$invalid = array(
			array( State_Machine::ALBUM_IDLE, State_Machine::ALBUM_WATERMARKING ),
			array( State_Machine::ALBUM_IDLE, State_Machine::ALBUM_ABORTED ),
			array( State_Machine::ALBUM_UPLOADING, State_Machine::ALBUM_DELETING ),
			array( State_Machine::ALBUM_DELETING, State_Machine::ALBUM_IDLE ),
			array( State_Machine::ALBUM_DELETING, State_Machine::ALBUM_UPLOADING ),
			array( State_Machine::ALBUM_WATERMARKING, State_Machine::ALBUM_DELETING ),
			array( State_Machine::ALBUM_WATERMARKING, State_Machine::ALBUM_UPLOADING ),
		);

		foreach ( $invalid as $idx => list( $from, $to ) ) {
			$this->assertFalse(
				State_Machine::is_valid_transition( 'album', $from, $to ),
				"Album transition #{$idx} should be invalid: {$from} → {$to}"
			);
		}
	}

	/**
	 * Every valid photo transition.
	 */
	public function test_valid_photo_transitions(): void {
		$valid = array(
			array( State_Machine::PHOTO_UPLOADED, State_Machine::PHOTO_WATERMARKING ),
			array( State_Machine::PHOTO_UPLOADED, State_Machine::PHOTO_FAILED ),
			array( State_Machine::PHOTO_UPLOADED, State_Machine::PHOTO_DELETED ),
			array( State_Machine::PHOTO_WATERMARKING, State_Machine::PHOTO_WATERMARKED ),
			array( State_Machine::PHOTO_WATERMARKING, State_Machine::PHOTO_FAILED ),
			array( State_Machine::PHOTO_WATERMARKING, State_Machine::PHOTO_DELETED ),
			array( State_Machine::PHOTO_WATERMARKED, State_Machine::PHOTO_DELETED ),
			array( State_Machine::PHOTO_FAILED, State_Machine::PHOTO_UPLOADED ),
			array( State_Machine::PHOTO_FAILED, State_Machine::PHOTO_WATERMARKING ),
			array( State_Machine::PHOTO_FAILED, State_Machine::PHOTO_DELETED ),
		);

		foreach ( $valid as $idx => list( $from, $to ) ) {
			$this->assertTrue(
				State_Machine::is_valid_transition( 'photo', $from, $to ),
				"Photo transition #{$idx} should be valid: {$from} → {$to}"
			);
		}
	}

	/**
	 * Every invalid photo transition.
	 */
	public function test_invalid_photo_transitions(): void {
		$invalid = array(
			array( State_Machine::PHOTO_UPLOADED, State_Machine::PHOTO_WATERMARKED ),
			array( State_Machine::PHOTO_WATERMARKED, State_Machine::PHOTO_UPLOADED ),
			array( State_Machine::PHOTO_WATERMARKED, State_Machine::PHOTO_WATERMARKING ),
			array( State_Machine::PHOTO_WATERMARKED, State_Machine::PHOTO_FAILED ),
			array( State_Machine::PHOTO_DELETED, State_Machine::PHOTO_UPLOADED ),
			array( State_Machine::PHOTO_DELETED, State_Machine::PHOTO_FAILED ),
		);

		foreach ( $invalid as $idx => list( $from, $to ) ) {
			$this->assertFalse(
				State_Machine::is_valid_transition( 'photo', $from, $to ),
				"Photo transition #{$idx} should be invalid: {$from} → {$to}"
			);
		}
	}

	/**
	 * Unknown entity type returns false.
	 */
	public function test_unknown_entity(): void {
		$this->assertFalse( State_Machine::is_valid_transition( 'widget', 'idle', 'deleting' ) );
	}

	/**
	 * Unknown source state returns false.
	 */
	public function test_unknown_source_state(): void {
		$this->assertFalse( State_Machine::is_valid_transition( 'album', 'nonexistent', 'idle' ) );
	}

	/**
	 * CAS album transition succeeds when query returns 1.
	 */
	public function test_transition_album_cas_success(): void {
		$orig = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = $this->mock_wpdb( 1 );

		$fsm = new State_Machine();
		$this->assertTrue( $fsm->transition_album( 1, State_Machine::ALBUM_IDLE, State_Machine::ALBUM_UPLOADING ) );

		$GLOBALS['wpdb'] = $orig;
	}

	/**
	 * CAS album transition fails when query returns 0.
	 */
	public function test_transition_album_cas_miss(): void {
		$orig = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = $this->mock_wpdb( 0 );

		$fsm = new State_Machine();
		$this->assertFalse( $fsm->transition_album( 1, State_Machine::ALBUM_IDLE, State_Machine::ALBUM_UPLOADING ) );

		$GLOBALS['wpdb'] = $orig;
	}

	/**
	 * CAS photo transition succeeds when query returns 1.
	 */
	public function test_transition_photo_cas_success(): void {
		$orig = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = $this->mock_wpdb( 1 );

		$fsm = new State_Machine();
		$this->assertTrue( $fsm->transition_photo( 1, State_Machine::PHOTO_UPLOADED, State_Machine::PHOTO_WATERMARKING ) );

		$GLOBALS['wpdb'] = $orig;
	}

	/**
	 * CAS photo transition fails when query returns 0.
	 */
	public function test_transition_photo_cas_miss(): void {
		$orig = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = $this->mock_wpdb( 0 );

		$fsm = new State_Machine();
		$this->assertFalse( $fsm->transition_photo( 1, State_Machine::PHOTO_UPLOADED, State_Machine::PHOTO_WATERMARKING ) );

		$GLOBALS['wpdb'] = $orig;
	}

	/**
	 * get_album returns null when the row is not found.
	 */
	public function test_get_album_returns_null_when_not_found(): void {
		$fsm = new State_Machine();
		$this->assertNull( $fsm->get_album( 999 ) );
	}

	/**
	 * get_photo returns null when the row is not found.
	 */
	public function test_get_photo_returns_null_when_not_found(): void {
		$fsm = new State_Machine();
		$this->assertNull( $fsm->get_photo( 999 ) );
	}

	/**
	 * is_valid_transition rejects invalid album transitions not in the graph.
	 */
	public function test_is_valid_transition_album_rejects_invalid(): void {
		$this->assertFalse( State_Machine::is_valid_transition( 'album', State_Machine::ALBUM_ABORTED, State_Machine::ALBUM_UPLOADING ) );
		$this->assertFalse( State_Machine::is_valid_transition( 'album', State_Machine::ALBUM_ABORTED, State_Machine::ALBUM_WATERMARKING ) );
		$this->assertFalse( State_Machine::is_valid_transition( 'album', State_Machine::ALBUM_DELETING, State_Machine::ALBUM_WATERMARKING ) );
	}

	/**
	 * is_valid_transition rejects invalid photo transitions not in the graph.
	 */
	public function test_is_valid_transition_photo_rejects_invalid(): void {
		$this->assertFalse( State_Machine::is_valid_transition( 'photo', State_Machine::PHOTO_DELETED, State_Machine::PHOTO_UPLOADED ) );
		$this->assertFalse( State_Machine::is_valid_transition( 'photo', State_Machine::PHOTO_DELETED, State_Machine::PHOTO_WATERMARKED ) );
		$this->assertFalse( State_Machine::is_valid_transition( 'photo', State_Machine::PHOTO_WATERMARKED, State_Machine::PHOTO_WATERMARKING ) );
	}

	/**
	 * Create a minimal wpdb mock with a configurable query return value.
	 */
	private function mock_wpdb( int $query_return ): object {
		return new class( $query_return ) {
			public string $last_error = '';
			public string $prefix     = 'wp_';

			public function __construct( private int $qr ) {}

			public function prepare( string $query, mixed ...$args ): string {
				return $query;
			}

			public function query( string $query ): int {
				return $this->qr;
			}

			public function get_row( string $query, string $output = OBJECT ) {
				return null;
			}
		};
	}
}
