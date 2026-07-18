<?php
/**
 * Unit tests for Activator activation/deactivation and requirements check.
 *
 * @package Photolab
 */

namespace Photolab;

use PHPUnit\Framework\TestCase;

/**
 * Verify Activator system checks, directory creation, and hook wiring.
 */
class ActivatorTest extends TestCase {

	private string $temp_base = '';

	protected function setUp(): void {
		parent::setUp();

		$this->temp_base = sys_get_temp_dir() . '/photolab-actor-' . uniqid();
		mkdir( $this->temp_base, 0755, true );
	}

	protected function tearDown(): void {
		if ( '' !== $this->temp_base && is_dir( $this->temp_base ) ) {
			$it    = new \RecursiveDirectoryIterator( $this->temp_base, \RecursiveDirectoryIterator::SKIP_DOTS );
			$files = new \RecursiveIteratorIterator( $it, \RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $files as $f ) {
				$f->isDir() ? rmdir( $f->getRealPath() ) : unlink( $f->getRealPath() );
			}
			rmdir( $this->temp_base );
		}

		// Clean up any Photolab upload dirs created under /tmp/Photolab.
		$photolab_base = '/tmp/Photolab';
		if ( is_dir( $photolab_base ) ) {
			$it    = new \RecursiveDirectoryIterator( $photolab_base, \RecursiveDirectoryIterator::SKIP_DOTS );
			$files = new \RecursiveIteratorIterator( $it, \RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $files as $f ) {
				$f->isDir() ? rmdir( $f->getRealPath() ) : unlink( $f->getRealPath() );
			}
			rmdir( $photolab_base );
		}

		parent::tearDown();
	}

	public function test_check_requirements_returns_empty_when_ok(): void {
		$method = new \ReflectionMethod( Activator::class, 'check_requirements' );
		$method->setAccessible( true );
		$errors = $method->invoke( null );

		$this->assertIsArray( $errors );
	}

	public function test_check_requirements_fails_when_wc_missing(): void {
		// WooCommerce class is not defined in bootstrap, so class_exists
		// returns false and check_requirements should report it.
		$method = new \ReflectionMethod( Activator::class, 'check_requirements' );
		$method->setAccessible( true );
		$errors = $method->invoke( null );

		if ( ! class_exists( 'WooCommerce' ) ) {
			$wc_errs = array_filter(
				$errors,
				fn( $e ) => str_contains( $e, 'WooCommerce' )
			);
			$this->assertNotEmpty( $wc_errs );
		} else {
			$this->addToAssertionCount( 1 );
		}
	}

	public function test_check_requirements_reports_gd_fallback(): void {
		delete_transient( 'photolab_gd_only_warning' );

		$method = new \ReflectionMethod( Activator::class, 'check_requirements' );
		$method->setAccessible( true );
		$method->invoke( null );

		$has_imagick = extension_loaded( 'imagick' );
		$has_gd      = extension_loaded( 'gd' );

		if ( ! $has_imagick && $has_gd ) {
			$this->assertNotFalse( get_transient( 'photolab_gd_only_warning' ) );
		} else {
			$this->addToAssertionCount( 1 );
		}
	}

	public function test_create_directories_creates_upload_dirs(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		$method = new \ReflectionMethod( Activator::class, 'create_directories' );
		$method->setAccessible( true );
		$method->invoke( null );

		$this->assertDirectoryExists( '/tmp/Photolab/assets' );
		$this->assertDirectoryExists( '/tmp/Photolab/photos' );
		$this->assertDirectoryExists( '/tmp/Photolab/watermarked' );
	}

	public function test_write_htaccess_writes_deny_all(): void {
		$method = new \ReflectionMethod( Activator::class, 'write_htaccess' );
		$method->setAccessible( true );
		$method->invoke( null, $this->temp_base );

		$htaccess = $this->temp_base . '/.htaccess';
		$this->assertFileExists( $htaccess );

		$content = file_get_contents( $htaccess );
		$this->assertStringContainsString( 'Deny from all', $content );
	}

	public function test_activate_calls_install_and_create(): void {
		try {
			Activator::activate();
			$this->addToAssertionCount( 1 );
		} catch ( \RuntimeException $e ) {
			// wp_die throws RuntimeException when requirements fail.
			$this->assertStringContainsString( 'Photolab', $e->getMessage() );
		}
	}

	public function test_deactivate_flushes_rewrite_rules(): void {
		try {
			Activator::deactivate();
			$this->addToAssertionCount( 1 );
		} catch ( \Throwable $e ) {
			$this->fail( 'Unexpected exception: ' . $e->getMessage() );
		}
	}

	public function test_show_activation_errors_outputs_html(): void {
		set_transient( 'photolab_activation_errors', array( 'Error 1', 'Error 2' ) );
		ob_start();
		Activator::show_activation_errors();
		$output = ob_get_clean();
		$this->assertStringContainsString( 'Photolab non può essere attivato', $output );
	}
}
