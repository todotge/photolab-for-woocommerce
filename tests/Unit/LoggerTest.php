<?php
/**
 * Unit tests for Logger.
 *
 * @package Photolab
 */

namespace Photolab;

use PHPUnit\Framework\TestCase;

// phpcs:disable NeutronStandard.Functions.TypeHint.NoArgumentType -- stubs

if ( ! function_exists( __NAMESPACE__ . '\add_action' ) ) {
	function add_action( string $tag, callable $callback, int $priority = 10, int $_accepted_args = 1 ): void {
		$GLOBALS['_photolab_actions'][ $tag ][] = $callback;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\do_action' ) ) {
	function do_action( string $tag, ...$args ): void {
		$GLOBALS['_photolab_do_action'][] = $tag;
		if ( isset( $GLOBALS['_photolab_actions'][ $tag ] ) ) {
			foreach ( $GLOBALS['_photolab_actions'][ $tag ] as $callback ) {
				$callback( ...$args );
			}
		}
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
 * Verify Logger static facade behaviour: context, sanitisation, retention, hooks.
 */
class LoggerTest extends TestCase {

	/**
	 * Clean logger state and stored actions before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		Logger::clear_context();
		if ( isset( $GLOBALS['_photolab_logger'] ) ) {
			$GLOBALS['_photolab_logger']->logs = array();
		}
		$GLOBALS['_photolab_actions']  = array();
		$GLOBALS['_photolab_do_action'] = array();
	}

	/**
	 * Logger::info delegates to wc_get_logger with the right level and message.
	 */
	public function test_log_calls_wc_get_logger(): void {
		Logger::info( 'test msg' );
		$logger = wc_get_logger();
		$this->assertSame( 'info', $logger->logs[0]['level'] );
		$this->assertSame( 'test msg', $logger->logs[0]['message'] );
	}

	/**
	 * set_context stores a value that appears in subsequent log context.
	 */
	public function test_set_context_stores_value(): void {
		Logger::set_context( 'user_id', 1 );
		Logger::info( 'ctx test' );
		$logger = wc_get_logger();
		$this->assertSame( 1, $logger->logs[0]['context']['user_id'] );
	}

	/**
	 * clear_context removes all auto-context from subsequent logs.
	 */
	public function test_clear_context_resets(): void {
		Logger::set_context( 'user_id', 1 );
		Logger::clear_context();
		Logger::info( 'after clear' );
		$logger = wc_get_logger();
		$this->assertArrayNotHasKey( 'user_id', $logger->logs[0]['context'] );
	}

	/**
	 * Call-site context wins over auto-context on key collision.
	 */
	public function test_context_merges_call_site_wins(): void {
		Logger::set_context( 'key', 'auto' );
		Logger::info( 'merge', array( 'key' => 'manual' ) );
		$logger = wc_get_logger();
		$this->assertSame( 'manual', $logger->logs[0]['context']['key'] );
	}

	/**
	 * Default source is 'todot-photolab' when none is supplied.
	 */
	public function test_default_source_is_photolab(): void {
		Logger::info( 'no source' );
		$logger = wc_get_logger();
		$this->assertSame( 'todot-photolab', $logger->logs[0]['context']['source'] );
	}

	/**
	 * Custom source is preserved when explicitly provided.
	 */
	public function test_custom_source_is_preserved(): void {
		Logger::info( 'custom source', array( 'source' => 'custom' ) );
		$logger = wc_get_logger();
		$this->assertSame( 'custom', $logger->logs[0]['context']['source'] );
	}

	/**
	 * Context keys containing "password" are redacted.
	 */
	public function test_sanitize_context_redacts_password(): void {
		Logger::info( 'secret', array( 'password' => 'secret123' ) );
		$logger = wc_get_logger();
		$this->assertSame( '***REDACTED***', $logger->logs[0]['context']['password'] );
	}

	/**
	 * cleanup_old_logs deletes expired log files.
	 */
	public function test_cleanup_old_logs_deletes_expired(): void {
		if ( ! defined( 'WC_LOG_DIR' ) ) {
			define( 'WC_LOG_DIR', '/tmp/photolab-logger-test/' );
		}
		$dir = WC_LOG_DIR;
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}
		$file = $dir . 'photolab-test-expired.log';
		touch( $file, time() - 40 * DAY_IN_SECONDS );

		$count = Logger::cleanup_old_logs();

		$this->assertSame( 1, $count );
		$this->assertFileDoesNotExist( $file );
	}

	/**
	 * cleanup_old_logs keeps recent log files.
	 */
	public function test_cleanup_old_logs_skips_recent(): void {
		if ( ! defined( 'WC_LOG_DIR' ) ) {
			define( 'WC_LOG_DIR', '/tmp/photolab-logger-test/' );
		}
		$dir = WC_LOG_DIR;
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}
		$file = $dir . 'photolab-test-recent.log';
		touch( $file, time() - 5 * DAY_IN_SECONDS );

		$count = Logger::cleanup_old_logs();

		$this->assertSame( 0, $count );
		$this->assertFileExists( $file );
		unlink( $file );
		rmdir( $dir );
	}

	/**
	 * Logger::error triggers the photolab_log_error action.
	 */
	public function test_error_triggers_photolab_log_error_action(): void {
		$fired = array();
		add_action( 'photolab_log_error', function ( $msg, $ctx ) use ( &$fired ) {
			$fired = array( 'message' => $msg, 'context' => $ctx );
		} );

		Logger::error( 'something broke' );

		$this->assertSame( 'something broke', $fired['message'] );
		$this->assertSame( 'todot-photolab', $fired['context']['source'] );
	}
}
