<?php
/**
 * Unit tests for Lock.
 *
 * @package Photolab
 */

namespace Photolab;

use PHPUnit\Framework\TestCase;

// phpcs:disable NeutronStandard.Functions.TypeHint.NoArgumentType -- stubs

if ( ! function_exists( __NAMESPACE__ . '\wp_using_ext_object_cache' ) ) {
	function wp_using_ext_object_cache(): bool {
		return $GLOBALS['_photolab_ext_cache'] ?? \wp_using_ext_object_cache();
	}
}

if ( ! function_exists( __NAMESPACE__ . '\get_transient' ) ) {
	function get_transient( string $key ) {
		return $GLOBALS['_photolab_transients'][ $key ] ?? false;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\set_transient' ) ) {
	function set_transient( string $key, mixed $value, int $ttl = 0 ): bool {
		$GLOBALS['_photolab_transients'][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\delete_transient' ) ) {
	function delete_transient( string $key ): bool {
		unset( $GLOBALS['_photolab_transients'][ $key ] );
		return true;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\wc_get_logger' ) ) {
	function wc_get_logger() {
		if ( ! isset( $GLOBALS['_photolab_logger'] ) ) {
			$GLOBALS['_photolab_logger'] = new class() {
				public array $logs = array();

				public function __call( string $name, array $args ) {
					$this->logs[] = array(
						'level'   => $name,
						'message' => $args[0],
						'context' => $args[1] ?? array(),
					);
				}
			};
		}
		return $GLOBALS['_photolab_logger'];
	}
}

// phpcs:enable

/**
 * Verify Lock acquire / release / is_available with the transient backend.
 */
class LockTest extends TestCase {

	/**
	 * Clean up any lock transients and logger state before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		unset( $GLOBALS['_photolab_ext_cache'] );
		$GLOBALS['_photolab_transients'] = array();
	}

	/**
	 * acquire returns true when the key does not yet exist.
	 */
	public function test_acquire_returns_true_on_success(): void {
		$this->assertTrue( Lock::acquire( 'test_key' ) );
	}

	/**
	 * acquire returns false when the key is already held.
	 */
	public function test_acquire_returns_false_when_key_exists(): void {
		Lock::acquire( 'test_key' );
		$this->assertFalse( Lock::acquire( 'test_key' ) );
	}

	/**
	 * release removes the lock so the key can be re-acquired.
	 */
	public function test_release_removes_key(): void {
		Lock::acquire( 'rel_key' );
		Lock::release( 'rel_key' );
		$this->assertTrue( Lock::acquire( 'rel_key' ) );
	}

	/**
	 * A lock that has expired (transient removed) can be acquired again.
	 */
	public function test_lock_expires_after_timeout(): void {
		Lock::acquire( 'exp_key' );
		delete_transient( 'photolab_lock_exp_key' );
		$this->assertTrue( Lock::acquire( 'exp_key' ) );
	}

	/**
	 * release on a non-existent key does not throw.
	 */
	public function test_release_safe_on_missing_lock(): void {
		Lock::release( 'nonexistent' );
		$this->assertTrue( true );
	}

	/**
	 * is_available returns true when an external object cache is present.
	 */
	public function test_is_available_returns_true(): void {
		$GLOBALS['_photolab_ext_cache'] = true;
		$this->assertTrue( Lock::is_available() );
	}
}
