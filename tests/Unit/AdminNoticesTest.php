<?php
/**
 * Unit tests for Admin_Notices persistent admin notice system.
 *
 * @package Photolab
 */

namespace Photolab;

use PHPUnit\Framework\TestCase;

/**
 * Verify Admin_Notices add/remove/clear/render/dismiss functionality.
 */
class AdminNoticesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		delete_option( Admin_Notices::OPTION_KEY );
	}

	protected function tearDown(): void {
		delete_option( Admin_Notices::OPTION_KEY );
		parent::tearDown();
	}

	public function test_add_stores_notice(): void {
		Admin_Notices::add( 'test-slug', 'Test message', 'error' );
		$stored = get_option( Admin_Notices::OPTION_KEY, array() );
		$this->assertArrayHasKey( 'test-slug', $stored );
		$this->assertSame( 'Test message', $stored['test-slug']['message'] );
		$this->assertSame( 'error', $stored['test-slug']['type'] );
	}

	public function test_remove_deletes_notice(): void {
		Admin_Notices::add( 'test-slug', 'Test message', 'error' );
		Admin_Notices::remove( 'test-slug' );
		$stored = get_option( Admin_Notices::OPTION_KEY, array() );
		$this->assertArrayNotHasKey( 'test-slug', $stored );
	}

	public function test_clear_all_empties_store(): void {
		Admin_Notices::add( 'slug-a', 'Message A' );
		Admin_Notices::add( 'slug-b', 'Message B' );
		Admin_Notices::clear_all();
		$stored = get_option( Admin_Notices::OPTION_KEY, array() );
		$this->assertEmpty( $stored );
	}

	public function test_render_outputs_notice_html(): void {
		Admin_Notices::add( 'test-slug', 'Test message', 'error' );
		ob_start();
		Admin_Notices::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $output );
		$this->assertStringContainsString( 'Test message', $output );
		$this->assertStringContainsString( 'test-slug', $output );
	}

	public function test_handle_dismiss_clears_notice(): void {
		$_POST['nonce'] = wp_create_nonce( Admin_Notices::AJAX_ACTION );
		$_POST['slug']  = 'test-slug';

		Admin_Notices::add( 'test-slug', 'Dismiss me', 'error' );

		try {
			Admin_Notices::handle_dismiss();
		} catch ( \RuntimeException $e ) {
			// wp_die throws RuntimeException — expected.
		}

		$stored = get_option( Admin_Notices::OPTION_KEY, array() );
		$this->assertArrayNotHasKey( 'test-slug', $stored );
	}

	public function test_render_skips_when_empty(): void {
		ob_start();
		Admin_Notices::render();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'photolab-notice', (string) $output );
	}

	public function test_add_with_invalid_type_defaults(): void {
		Admin_Notices::add( 'bad-type', 'Invalid type test', 'invalid_type' );
		$stored = get_option( Admin_Notices::OPTION_KEY, array() );
		$this->assertSame( 'error', $stored['bad-type']['type'] );
	}
}
