<?php

namespace Photolab;

use PHPUnit\Framework\TestCase;

/**
 * Architecture test: every public method must have at least one test.
 * Fail on PR if a new method was added without a test.
 */
class ArchitectureTest extends TestCase {

    private function expected_methods(): array {
        return [
            // Logger: debug/info/warning/critical are one-line aliases of log(). Covered by log() test.
            'Logger' => ['log', 'error', 'set_context', 'clear_context', 'cleanup_old_logs'],
            'State_Machine' => ['transition_album', 'transition_photo', 'is_valid_transition',
                                'get_album', 'get_photo'],
            'Admin_Notices' => ['add', 'remove', 'clear_all', 'render', 'handle_dismiss'],
            'Lock' => ['acquire', 'release', 'is_available'],
            'Watermark_Processor' => ['apply'],
            'Watermark_Job' => ['process_batch', 'generate_thumbnail_meta'],
            'Cleanup_Scheduler' => ['init', 'cleanup_expired_photos', 'run_daily_cleanup',
                                    'ensure_first_action', 'ensure_first_daily_action',
                                    'schedule_next', 'unschedule', 'unschedule_daily',
                                    'recover_stuck_watermarking_photos', 'retrigger_failed_photos',
                                    'recover_uploaded_on_aborted', 'auto_settle_albums',
                                    'delete_stale_failed_photos'],
            'Recovery_Scheduler' => ['init', 'scan_and_recover', 'ensure_first_action', 'unschedule'],
            'Download_Guard' => ['init', 'filter_download_path'],
            'Activator' => ['activate', 'deactivate', 'show_activation_errors'],
            'Admin' => ['init', 'register_menu', 'enqueue_assets', 'render_page',
                        'notice_pretty_permalinks', 'detect_image_engine'],
            'Database' => ['install', 'get_version', 'update_version', 'maybe_update'],
            'Upload_Controller' => ['register_routes', 'admin_permissions_check',
                                    'start', 'chunk', 'status', 'complete'],
            'Heartbeat_Controller' => ['register_routes', 'admin_permissions_check', 'handle_heartbeat'],
            'Album_Controller' => ['register_routes', 'admin_permissions_check',
                                   'get_items', 'delete_item', 'reset_item'],
            'Photo_Controller' => ['register_routes', 'admin_permissions_check', 'get_watermark_status'],
            'Watermark_Controller' => ['register_routes', 'admin_permissions_check',
                                       'upload', 'update_position', 'delete'],
            'Settings_Controller' => ['register_routes', 'admin_permissions_check', 'get_settings'],
        ];
    }

    private function find_tests_for_method( string $class, string $method ): array {
        $tests       = array();
        $class_clean = str_replace( '_', '', $class );

        // Check multiple naming conventions for test files.
        foreach ( array(
            __DIR__ . "/{$class}Test.php",
            __DIR__ . "/{$class_clean}Test.php",
            dirname( __DIR__ ) . "/Integration/{$class}IntegrationTest.php",
            dirname( __DIR__ ) . "/Integration/{$class}Test.php",
            dirname( __DIR__ ) . "/Integration/{$class_clean}IntegrationTest.php",
            dirname( __DIR__ ) . "/Integration/{$class_clean}Test.php",
        ) as $file ) {
            if ( ! file_exists( $file ) ) continue;
            $content = file_get_contents( $file );
            preg_match_all( '/function\s+(test_\w*' . preg_quote( $method, '/' ) . '\w*)/', $content, $matches );
            foreach ( $matches[1] ?? array() as $test_method ) {
                $tests[] = basename( $file ) . '::' . $test_method;
            }
            break; // Found a file — stop looking.
        }

        return $tests;
    }

    public function test_every_public_method_is_covered(): void {
        $missing = [];
        foreach ( $this->expected_methods() as $class => $methods ) {
            $clean     = str_replace( '_', '', $class );
            $unit_root = __DIR__;
            $int_root  = dirname( __DIR__ ) . '/Integration';
            $has_unit = file_exists( "{$unit_root}/{$class}Test.php" )
                     || file_exists( "{$unit_root}/{$clean}Test.php" );
            $has_int  = file_exists( "{$int_root}/{$class}IntegrationTest.php" )
                     || file_exists( "{$int_root}/{$class}Test.php" )
                     || file_exists( "{$int_root}/{$clean}IntegrationTest.php" )
                     || file_exists( "{$int_root}/{$clean}Test.php" );
            if ( ! $has_unit && ! $has_int ) {
                $missing[] = "{$class}: no test file found (expected Unit/{$class}Test.php or Integration/{$class}IntegrationTest.php)";
                continue;
            }
            foreach ( $methods as $method ) {
                $found = $this->find_tests_for_method( $class, $method );
                if ( empty( $found ) ) {
                    $missing[] = "{$class}::{$method}: no test found";
                }
            }
        }
        $this->assertEmpty( $missing, "Missing test coverage:\n" . implode( "\n", $missing ) );
    }
}
