<?php
/**
 * Optional distributed lock helper for Photolab (FASE 8).
 *
 * Provides an extra layer of race-condition protection on top of the
 * MySQL-level CAS guards used throughout the upload pipeline. Designed to
 * prevent two `/upload/chunk` requests for the same album from interleaving
 * across multiple application servers (e.g. behind a load balancer) when an
 * external object cache such as Redis is configured.
 *
 * Behaviour matrix:
 * - `wp_using_ext_object_cache()` true (Redis or similar) → uses
 *   `wp_cache_add()` which is atomic on Redis (`SET NX`).
 * - No external object cache → falls back to a transient-backed best-effort
 *   lock. There is a tiny race window between the get/set, but the upload
 *   pipeline still relies on the FSM CAS as the final atomic guarantee.
 * - `apply_filters( 'photolab_use_distributed_lock', true )` returning false
 *   disables the lock entirely; `acquire()` becomes a no-op that always
 *   succeeds. This keeps the behaviour identical to pre-FASE-8 deployments
 *   for hosts where distributed locking is undesirable.
 *
 * The lock is intentionally an additive safety net — it is NEVER a
 * replacement for the CAS pattern enforced by `State_Machine::transition_*`.
 *
 * @package Photolab
 * @since   2.1.0
 */

namespace Photolab;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static facade around an opportunistic distributed lock.
 *
 * @since 2.1.0
 */
class Lock {

	/**
	 * Object-cache group used for distributed locks.
	 *
	 * Kept in its own group so cache flushes targeted at Photolab data do not
	 * accidentally release in-flight locks.
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'photolab-locks';

	/**
	 * Transient key prefix for the fallback path (no external object cache).
	 *
	 * @var string
	 */
	private const TRANSIENT_PREFIX = 'photolab_lock_';

	/**
	 * Logger source for lock events.
	 *
	 * @var string
	 */
	private const LOG_SOURCE = 'photolab-lock';

	/**
	 * Backend identifier returned by `detect_backend()` when distributed
	 * locking is active and an external object cache is in use.
	 *
	 * @var string
	 */
	private const BACKEND_CACHE = 'object-cache';

	/**
	 * Backend identifier returned by `detect_backend()` when the transient
	 * fallback is the active path.
	 *
	 * @var string
	 */
	private const BACKEND_TRANSIENT = 'transient';

	/**
	 * Backend identifier returned by `detect_backend()` when the lock has
	 * been disabled via the `photolab_use_distributed_lock` filter.
	 *
	 * @var string
	 */
	private const BACKEND_DISABLED = 'disabled';

	/**
	 * Try to acquire a named lock.
	 *
	 * Returns true when the caller is now the lock holder and must call
	 * `release()` after finishing (typically in a `finally` block). Returns
	 * false only when another worker is currently holding the same key with
	 * an external object cache backend — the transient fallback always
	 * succeeds because the underlying option is best-effort and the FSM CAS
	 * provides the actual atomicity downstream.
	 *
	 * The lock auto-expires after `$timeout` seconds so a crashed worker
	 * cannot deadlock the album indefinitely.
	 *
	 * @since 2.1.0
	 *
	 * @param string $key     Lock name. Should be globally unique for the
	 *                        resource being protected, e.g.
	 *                        `photolab_chunk_{album_id}`.
	 * @param int    $timeout Auto-expiry in seconds. Default 30.
	 * @return bool True when the lock was acquired, false when another
	 *              process already holds it.
	 */
	public static function acquire( string $key, int $timeout = 30 ): bool {
		$backend = self::detect_backend();

		if ( self::BACKEND_DISABLED === $backend ) {
			// Filter explicitly disabled the distributed lock — behave as if
			// we acquired it so callers proceed without branching.
			return true;
		}

		$timeout = max( 1, $timeout );

		if ( self::BACKEND_CACHE === $backend ) {
			$acquired = (bool) wp_cache_add( $key, 1, self::CACHE_GROUP, $timeout );
		} else {
			$transient_key = self::TRANSIENT_PREFIX . $key;

			if ( false !== get_transient( $transient_key ) ) {
				$acquired = false;
			} else {
				set_transient( $transient_key, 1, $timeout );
				$acquired = true;
			}
		}

		if ( $acquired ) {
			Logger::debug(
				sprintf( 'Lock::acquire — acquired key=%s backend=%s timeout=%ds.', $key, $backend, $timeout ),
				array( 'source' => self::LOG_SOURCE )
			);
		}

		return $acquired;
	}

	/**
	 * Release a previously acquired lock.
	 *
	 * Safe to call unconditionally — missing or expired locks are a no-op.
	 * Failures are logged at warning level but never thrown; the lock has a
	 * TTL and will expire on its own if cleanup misbehaves.
	 *
	 * @since 2.1.0
	 *
	 * @param string $key Lock name passed to `acquire()`.
	 * @return void
	 */
	public static function release( string $key ): void {
		$backend = self::detect_backend();

		if ( self::BACKEND_DISABLED === $backend ) {
			return;
		}

		try {
			if ( self::BACKEND_CACHE === $backend ) {
				wp_cache_delete( $key, self::CACHE_GROUP );
			} else {
				delete_transient( self::TRANSIENT_PREFIX . $key );
			}

			Logger::debug(
				sprintf( 'Lock::release — released key=%s backend=%s.', $key, $backend ),
				array( 'source' => self::LOG_SOURCE )
			);
		} catch ( \Throwable $e ) {
			Logger::warning(
				sprintf(
					'Lock::release — failed key=%s backend=%s error=%s. Lock will expire via TTL.',
					$key,
					$backend,
					$e->getMessage()
				),
				array( 'source' => self::LOG_SOURCE )
			);
		}
	}

	/**
	 * Whether a true distributed lock backend is available.
	 *
	 * Returns true only when an external object cache is in use AND the
	 * `photolab_use_distributed_lock` filter has not been disabled. Callers
	 * may use this to decide whether to attempt a lock at all — but in
	 * practice `acquire()` already short-circuits when the lock is disabled
	 * so an explicit `is_available()` check is rarely needed.
	 *
	 * @since 2.1.0
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return self::BACKEND_CACHE === self::detect_backend();
	}

	/**
	 * Resolve the active backend based on filter and runtime environment.
	 *
	 * Order of precedence:
	 *  1. If the `photolab_use_distributed_lock` filter returns false →
	 *     `disabled`.
	 *  2. If `wp_using_ext_object_cache()` is true → `object-cache`.
	 *  3. Otherwise → `transient` (best-effort fallback).
	 *
	 * @since 2.1.0
	 *
	 * @return string One of self::BACKEND_DISABLED, self::BACKEND_CACHE,
	 *                self::BACKEND_TRANSIENT.
	 */
	private static function detect_backend(): string {
		/**
		 * Filters whether to use distributed locking for upload chunk
		 * processing.
		 *
		 * Distributed locking adds an extra layer of race-condition
		 * protection in multi-server environments. When disabled (or when
		 * an external object cache is unavailable), the plugin falls back
		 * to database-level CAS (Compare-And-Swap) which is sufficient for
		 * single-server setups.
		 *
		 * @since 2.1.0
		 *
		 * @param bool $use_lock Whether to attempt distributed locking.
		 *                       Default true.
		 */
		$use_lock = (bool) apply_filters( 'photolab_use_distributed_lock', true );

		if ( ! $use_lock ) {
			return self::BACKEND_DISABLED;
		}

		if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
			return self::BACKEND_CACHE;
		}

		return self::BACKEND_TRANSIENT;
	}
}
