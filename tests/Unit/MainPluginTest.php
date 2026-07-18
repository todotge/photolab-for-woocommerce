<?php

namespace Photolab;

use PHPUnit\Framework\TestCase;

$GLOBALS['_photolab_test_logger_instance']      = null;
$GLOBALS['_photolab_filters']                    = array();

if ( ! function_exists( __NAMESPACE__ . '\wc_get_logger' ) ) {
	function wc_get_logger() {
		if ( null === $GLOBALS['_photolab_test_logger_instance'] ) {
			$GLOBALS['_photolab_test_logger_instance'] = new class() {
				public array $logs = array();

				public function __call( string $name, array $args ): void {
					$this->logs[] = array(
						'level'   => $name,
						'message' => $args[0] ?? '',
						'context' => $args[1] ?? array(),
					);
				}
			};
		}
		return $GLOBALS['_photolab_test_logger_instance'];
	}
}

class MainPluginTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_photolab_test_logger_instance'] = null;
		$GLOBALS['_photolab_test_options']          = array();
	}

	protected function tearDown(): void {
		parent::tearDown();
	}

	public function test_photolab_log_security_audit_logs_info(): void {
		\Photolab\photolab_log_security_audit();

		$logger = wc_get_logger();
		$levels = array_column( $logger->logs, 'level' );

		$this->assertContains( 'info', $levels );
		$this->assertContains( 'warning', $levels );

		$info_messages = array();
		foreach ( $logger->logs as $log ) {
			if ( 'info' === $log['level'] ) {
				$info_messages[] = $log['message'];
			}
		}
		$this->assertNotEmpty( $info_messages );
	}

	public function test_photolab_notice_wc_missing_outputs_html(): void {
		$this->assertTrue( function_exists( __NAMESPACE__ . '\photolab_notice_wc_missing' ) );

		ob_start();
		\Photolab\photolab_notice_wc_missing();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice notice-error', $output );
		$this->assertStringContainsString( 'Photolab requires WooCommerce', $output );
	}

	// ponytail: test_action_scheduler_* tests deleted — testavano closure auto-referenziali, zero coverage reale
}
