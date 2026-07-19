<?php
/**
 * Centralised logging helper for Photolab.
 *
 * Wraps wc_get_logger() with automatic source tagging, structured per-request
 * context (user_id, album_id, photo_id, …), sensitive-key redaction, log
 * retention, and a photolab_log_error filter so third-party plugins can react
 * to errors.
 *
 * @package Photolab
 */

namespace Photolab;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static logging facade used throughout the Photolab plugin.
 *
 * Every log entry automatically receives 'source' => 'photolab' unless an
 * alternative source is supplied in $context. Callers can also push request-
 * scoped context (user_id, album_id, …) via {@see self::set_context()} so
 * downstream Logger calls inherit it without repeating the values.
 *
 * @since 2.0.0 Added structured context, retention, sanitisation. The legacy
 *              info()/debug()/warning()/error() calls remain identical from
 *              the caller's point of view — they are now thin aliases over
 *              {@see self::log()}.
 */
class Logger {

	/**
	 * Default log source — maps to a single WooCommerce log file.
	 *
	 * @var string
	 */
	const SOURCE = 'photolab';

	/**
	 * Default log retention in days. Filterable via `photolab_log_retention_days`.
	 *
	 * @since 2.0.0
	 *
	 * @var int
	 */
	const DEFAULT_RETENTION_DAYS = 30;

	/**
	 * Minimum retention floor enforced regardless of the filter value (safety).
	 *
	 * @since 2.0.0
	 *
	 * @var int
	 */
	const MIN_RETENTION_DAYS = 7;

	/**
	 * Request-scoped auto-context merged into every log call.
	 *
	 * Populated by {@see self::set_context()} at the top of REST callbacks and
	 * Action Scheduler job handlers; cleared at the end of those entry points
	 * via {@see self::clear_context()} so the values do not leak into the next
	 * request handled by the same long-running PHP worker.
	 *
	 * @since 2.0.0
	 *
	 * @var array<string, mixed>
	 */
	private static array $global_context = array();

	/**
	 * Substring matchers used by {@see self::sanitize_context()} to redact
	 * sensitive payloads from log lines. Filterable via
	 * `photolab_log_sensitive_keys`.
	 *
	 * @since 2.0.0
	 *
	 * @var string[]
	 */
	private const SENSITIVE_KEY_HINTS = array(
		'password',
		'token',
		'secret',
		'api_key',
		'apikey',
		'auth',
		'authorization',
		'email',
		'user_email',
	);

	/**
	 * Log a message at the given level with structured context.
	 *
	 * The call-site context wins on key collision with the auto-context. The
	 * resulting array is passed through {@see self::sanitize_context()} and
	 * always carries a `source` key so WooCommerce routes the entry to the
	 * right log file.
	 *
	 * @since 2.0.0
	 *
	 * @param string $level   One of debug|info|notice|warning|error|critical|alert|emergency.
	 * @param string $message Human-readable log entry (English, not translated).
	 * @param array  $context Additional context. Merged on top of the request auto-context.
	 * @return void
	 */
	public static function log( string $level, string $message, array $context = array() ): void {
		$logger = wc_get_logger();
		if ( ! $logger ) {
			return;
		}

		$resolved = self::resolve_context( $context );

		$logger->{$level}( $message, $resolved );

		if ( 'error' === $level ) {
			/**
			 * Fires after a Photolab error is written to the log.
			 *
			 * @param string $message The error message.
			 * @param array  $context The logger context array (always contains 'source').
			 */
			do_action( 'photolab_log_error', $message, $resolved );
		}
	}

	/**
	 * Log a debug-level message (alias of {@see self::log()}).
	 *
	 * @param string $message Human-readable log entry.
	 * @param array  $context Optional logger context.
	 * @return void
	 */
	public static function debug( string $message, array $context = array() ): void {
		self::log( 'debug', $message, $context );
	}

	/**
	 * Log an informational message (alias of {@see self::log()}).
	 *
	 * @param string $message Human-readable log entry.
	 * @param array  $context Optional logger context.
	 * @return void
	 */
	public static function info( string $message, array $context = array() ): void {
		self::log( 'info', $message, $context );
	}

	/**
	 * Log a warning (alias of {@see self::log()}).
	 *
	 * @param string $message Human-readable log entry.
	 * @param array  $context Optional logger context.
	 * @return void
	 */
	public static function warning( string $message, array $context = array() ): void {
		self::log( 'warning', $message, $context );
	}

	/**
	 * Log an error (alias of {@see self::log()}). Triggers the
	 * `photolab_log_error` action.
	 *
	 * @param string $message Human-readable log entry.
	 * @param array  $context Optional logger context.
	 * @return void
	 */
	public static function error( string $message, array $context = array() ): void {
		self::log( 'error', $message, $context );
	}

	/**
	 * Log a critical-level message (alias of {@see self::log()}).
	 *
	 * @since 2.0.0
	 *
	 * @param string $message Human-readable log entry.
	 * @param array  $context Optional logger context.
	 * @return void
	 */
	public static function critical( string $message, array $context = array() ): void {
		self::log( 'critical', $message, $context );
	}

	// =========================================================================
	// Auto-context (request-scoped)
	// =========================================================================

	/**
	 * Push (or remove) a request-scoped context value.
	 *
	 * Setting the value to `null` removes the key. Useful at the top of REST
	 * callbacks or Action Scheduler hooks: every subsequent Logger call within
	 * the same request inherits the value without having to repeat it.
	 *
	 * @since 2.0.0
	 *
	 * @param string $key   Context key (e.g. 'user_id', 'album_id').
	 * @param mixed  $value Context value. Pass null to remove the key.
	 * @return void
	 */
	public static function set_context( string $key, mixed $value ): void {
		if ( '' === $key ) {
			return;
		}

		if ( null === $value ) {
			unset( self::$global_context[ $key ] );
		} else {
			self::$global_context[ $key ] = $value;
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$logger = wc_get_logger();
			if ( $logger ) {
				$logger->debug(
					sprintf( 'Logger::set_context — key=%s.', $key ),
					array( 'source' => 'photolab-logger' )
				);
			}
		}
	}

	/**
	 * Clear all request-scoped context.
	 *
	 * Call at the end of every REST callback and Action Scheduler hook so the
	 * accumulated state does not leak into the next request handled by the
	 * same PHP worker (relevant for FPM, mod_php with KeepAlive, and cron).
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public static function clear_context(): void {
		if ( empty( self::$global_context ) ) {
			return;
		}

		self::$global_context = array();

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$logger = wc_get_logger();
			if ( $logger ) {
				$logger->debug(
					'Logger::clear_context — auto-context reset.',
					array( 'source' => 'photolab-logger' )
				);
			}
		}
	}

	// =========================================================================
	// Retention
	// =========================================================================

	/**
	 * Delete WooCommerce log files older than the retention period.
	 *
	 * Restricted to `photolab-*.log` so other plugins are never touched.
	 * Retention defaults to {@see self::DEFAULT_RETENTION_DAYS} and is
	 * filterable via `photolab_log_retention_days` (clamped to a minimum of
	 * {@see self::MIN_RETENTION_DAYS}).
	 *
	 * Called by {@see Cleanup_Scheduler::run_daily_cleanup()}.
	 *
	 * @since 2.0.0
	 *
	 * @return int Number of log files deleted.
	 */
	public static function cleanup_old_logs(): int {
		/* @var int $days */
		$days = (int) apply_filters( 'photolab_log_retention_days', self::DEFAULT_RETENTION_DAYS );

		if ( $days < self::MIN_RETENTION_DAYS ) {
			$days = self::MIN_RETENTION_DAYS;
		}

		$dir = self::resolve_log_directory();
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return 0;
		}

		$cutoff  = time() - ( $days * DAY_IN_SECONDS );
		$pattern = trailingslashit( $dir ) . 'photolab*.log';
		$files   = glob( $pattern );

		if ( ! is_array( $files ) || empty( $files ) ) {
			self::log(
				'info',
				sprintf( 'Logger::cleanup_old_logs — nothing to delete. retention_days=%d.', $days ),
				array( 'source' => 'photolab-logger' )
			);
			return 0;
		}

		$deleted = 0;

		foreach ( $files as $path ) {
			if ( ! is_file( $path ) ) {
				continue;
			}

			$mtime = filemtime( $path );
			if ( false === $mtime || $mtime >= $cutoff ) {
				continue;
			}

		if ( wp_delete_file( $path ) ) {
				++$deleted;
			} else {
				self::log(
					'warning',
					sprintf( 'Logger::cleanup_old_logs — unlink failed: %s', $path ),
					array( 'source' => 'photolab-logger' )
				);
			}
		}

		self::log(
			'info',
			'Log cleanup completed.',
			array(
				'source'         => 'photolab-logger',
				'deleted_files'  => $deleted,
				'retention_days' => $days,
			)
		);

		return $deleted;
	}

	// =========================================================================
	// Private
	// =========================================================================

	/**
	 * Build the final context for a log line.
	 *
	 * Merges the request auto-context, the call-site context (call-site wins),
	 * forces the `source` key, then redacts any sensitive payload via
	 * {@see self::sanitize_context()}.
	 *
	 * @param array $context Caller-supplied context.
	 * @return array<string, mixed>
	 */
	private static function resolve_context( array $context ): array {
		$resolved = array_merge( self::$global_context, $context );

		if ( ! isset( $resolved['source'] ) || '' === $resolved['source'] ) {
			$resolved['source'] = self::SOURCE;
		}

		return self::sanitize_context( $resolved );
	}

	/**
	 * Redact context keys whose name suggests sensitive data.
	 *
	 * The match is a case-insensitive substring check against
	 * {@see self::SENSITIVE_KEY_HINTS} (filterable via
	 * `photolab_log_sensitive_keys`). The value is replaced with a redacted
	 * marker so the log line still records that the key existed.
	 *
	 * Keep the hint list short and conservative — over-redacting harmless keys
	 * (`attachment_id`, `wc_product_id`) would hide useful debugging data.
	 *
	 * @since 2.0.0
	 *
	 * @param array $context Context array.
	 * @return array<string, mixed>
	 */
	private static function sanitize_context( array $context ): array {
		/* @var string[] $hints */
		$hints = (array) apply_filters( 'photolab_log_sensitive_keys', self::SENSITIVE_KEY_HINTS );
		$hints = array_values( array_filter( $hints, static fn( $h ) => is_string( $h ) && '' !== $h ) );

		if ( empty( $hints ) ) {
			return $context;
		}

		// single-pass preg_match replaces O(n*m) nested loop.
		$pattern = '/' . implode( '|', array_map( static fn( $h ) => preg_quote( $h, '/' ), $hints ) ) . '/i';

		foreach ( $context as $key => $value ) {
			if ( is_string( $key ) && preg_match( $pattern, $key ) ) {
				$context[ $key ] = '***REDACTED***';
			}
		}

		return $context;
	}

	/**
	 * Resolve the WooCommerce log directory in a way that survives older WC
	 * versions and uncommon WP layouts.
	 *
	 * @since 2.0.0
	 *
	 * @return string Absolute path with trailing slash, or '' on failure.
	 */
	private static function resolve_log_directory(): string {
		if ( defined( 'WC_LOG_DIR' ) ) {
			return (string) WC_LOG_DIR;
		}

		$upload = wp_upload_dir();
		$base   = (string) ( $upload['basedir'] ?? '' );

		if ( '' === $base ) {
			return '';
		}

		return trailingslashit( $base ) . 'wc-logs/';
	}
}
