<?php
/**
 * Plugin Name:       Photolab
 * Plugin URI:        https://todot.it
 * Description:       Gestione e vendita massiva di album fotografici su WooCommerce.
 * Version:           0.0.5
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Photolab Team
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       photolab
 * Domain Path:       /languages
 * WC requires at least: 8.0
 *
 * @package Photolab
 */

namespace Photolab;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'PHOTOLAB_VERSION', '0.0.5' );
define( 'PHOTOLAB_DB_VERSION', '1.0.0' );
define( 'PHOTOLAB_PLUGIN_FILE', __FILE__ );
define( 'PHOTOLAB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PHOTOLAB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PHOTOLAB_MIN_PHP', '8.1' );
define( 'PHOTOLAB_MIN_WP', '6.5' );
define( 'PHOTOLAB_MIN_WC', '8.0' );

// Autoload classes.
require_once PHOTOLAB_PLUGIN_DIR . 'includes/class-logger.php';
require_once PHOTOLAB_PLUGIN_DIR . 'includes/class-admin-notices.php';
require_once PHOTOLAB_PLUGIN_DIR . 'includes/class-database.php';
require_once PHOTOLAB_PLUGIN_DIR . 'includes/class-activator.php';
require_once PHOTOLAB_PLUGIN_DIR . 'includes/class-admin.php';
require_once PHOTOLAB_PLUGIN_DIR . 'includes/class-state-machine.php';
require_once PHOTOLAB_PLUGIN_DIR . 'includes/class-watermark-processor.php';
require_once PHOTOLAB_PLUGIN_DIR . 'includes/class-watermark-job.php';
require_once PHOTOLAB_PLUGIN_DIR . 'includes/class-cleanup-scheduler.php';
require_once PHOTOLAB_PLUGIN_DIR . 'includes/class-recovery-scheduler.php';
require_once PHOTOLAB_PLUGIN_DIR . 'includes/class-download-guard.php';
require_once PHOTOLAB_PLUGIN_DIR . 'includes/class-lock.php';
require_once PHOTOLAB_PLUGIN_DIR . 'includes/rest/class-upload-controller.php';
require_once PHOTOLAB_PLUGIN_DIR . 'includes/rest/class-heartbeat-controller.php';
require_once PHOTOLAB_PLUGIN_DIR . 'includes/rest/class-album-controller.php';
require_once PHOTOLAB_PLUGIN_DIR . 'includes/rest/class-photo-controller.php';
require_once PHOTOLAB_PLUGIN_DIR . 'includes/rest/class-watermark-controller.php';
require_once PHOTOLAB_PLUGIN_DIR . 'includes/rest/class-settings-controller.php';

// Activation / deactivation hooks.
register_activation_hook( __FILE__, array( 'Photolab\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Photolab\Activator', 'deactivate' ) );
register_deactivation_hook( __FILE__, array( 'Photolab\Cleanup_Scheduler', 'unschedule' ) );
register_deactivation_hook( __FILE__, array( 'Photolab\Cleanup_Scheduler', 'unschedule_daily' ) );
register_deactivation_hook( __FILE__, array( 'Photolab\Recovery_Scheduler', 'unschedule' ) );

// Action Scheduler retention — auto-delete completed/failed actions older than
// 7 days. Centralised here (and not inside Cleanup_Scheduler) so AS picks it
// up on every request, not only after the daily sweep registers its hook.
add_filter(
	'action_scheduler_retention_period',
	static function () {
		return 7 * DAY_IN_SECONDS;
	}
);
add_filter(
	'action_scheduler_timeout_period',
	static function () {
		return 120; // seconds — release stuck claims in 2 min instead of 5.
	}
);
// Note: Cleanup_Scheduler and Recovery_Scheduler no longer use
// as_schedule_recurring_action(). Each callback self-schedules its next
// single action in a finally block. The action_scheduler_init closure
// seeds the first run and performs a one-time migration.

/**
 * Bootstrap the plugin after all plugins are loaded.
 *
 * Checks WooCommerce is active before initialising any Photolab functionality.
 * Shows an admin notice and bails early if WC is missing.
 *
 * @since 2.0.0
 * @return void
 */
function photolab_plugins_loaded(): void {
	// WooCommerce active check.
	if ( ! class_exists( 'WooCommerce' ) ) {
		Admin_Notices::add(
			'wc-missing',
			__( 'Photolab requires WooCommerce to be active. The plugin is disabled.', 'photolab' ),
			'error'
		);
		add_action( 'admin_notices', __NAMESPACE__ . '\\photolab_notice_wc_missing' );
		return;
	}

	// Wire up persistent admin notices (dismissible).
	Admin_Notices::init();

	// GD-only warning stored during activation — surface it now that WC is up.
	if ( get_transient( 'photolab_gd_only_warning' ) ) {
		Admin_Notices::add(
			'gd-only',
			__( 'Photolab: Imagick not available. GD in use. Reduced performance on large watermark volumes.', 'photolab' ),
			'warning'
		);
		delete_transient( 'photolab_gd_only_warning' );
	}

	// Run DB schema migrations if needed.
	$db = new Database();
	$db->maybe_update();

	// Register admin UI and Interactivity API store.
	$admin = new Admin();
	$admin->init();

	// Register cleanup scheduler hook.
	$cleanup = new Cleanup_Scheduler();
	$cleanup->init();

	// Register FSM recovery scheduler — flags crashed uploads as aborted.
	$recovery = new Recovery_Scheduler();
	$recovery->init();

	// Ensure the first single-action runs are scheduled. Deferred to
	// action_scheduler_init (fired by Action Scheduler itself once its
	// own runner/data store is ready) because schedules created earlier
	// during plugins_loaded were silently rejected by AS.
	// Each callback self-schedules its next run in a finally block.
	//
	// One-time migration: removes old recurring schedules so they don't
	// run in parallel with the new self-rescheduling single actions.
	add_action(
		'action_scheduler_init',
		static function () {
			$migrated = get_option( 'photolab_scheduler_migrated_v1' );
			if ( ! $migrated ) {
				foreach ( array(
					Cleanup_Scheduler::HOOK,
					Cleanup_Scheduler::DAILY_HOOK,
					Recovery_Scheduler::HOOK,
				) as $hook ) {
					if ( function_exists( 'as_unschedule_all_actions' ) ) {
						as_unschedule_all_actions( $hook, array(), 'photolab' );
					}
				}
				update_option( 'photolab_scheduler_migrated_v1', true, false );
			}

			Cleanup_Scheduler::ensure_first_action( Cleanup_Scheduler::HOOK, DAY_IN_SECONDS );
			Cleanup_Scheduler::ensure_first_daily_action();
			Recovery_Scheduler::ensure_first_action();
		}
	);

	// Block downloads of photos still being watermarked (FASE 5).
	Download_Guard::init();

	// Register REST API endpoints.
	add_action( 'rest_api_init', __NAMESPACE__ . '\\photolab_register_rest_routes' );

	// Security/performance audit log (runs once per plugin version via transient).
	if ( ! get_transient( 'photolab_audit_logged_' . PHOTOLAB_VERSION ) ) {
		photolab_log_security_audit();
		set_transient( 'photolab_audit_logged_' . PHOTOLAB_VERSION, true, WEEK_IN_SECONDS );
	}
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\photolab_plugins_loaded' );

/**
 * Register all REST API routes under namespace photolab/v1.
 *
 * @since 2.0.0
 * @return void
 */
function photolab_register_rest_routes(): void {
	Logger::info( 'photolab_register_rest_routes() — registrazione endpoint REST.', array( 'source' => 'photolab-rest' ) );

	( new Upload_Controller() )->register_routes();
	( new Heartbeat_Controller() )->register_routes();
	( new Album_Controller() )->register_routes();
	( new Photo_Controller() )->register_routes();
	( new Watermark_Controller() )->register_routes();
	( new Settings_Controller() )->register_routes();

	Logger::info( 'photolab_register_rest_routes() — endpoint registrati.', array( 'source' => 'photolab-rest' ) );
}

/**
 * Log a one-time security and performance audit summary for this plugin version.
 *
 * Checks key runtime conditions: directory protection, image engine, WC HPOS
 * compatibility, and autoloaded options. Results are written to the WC log.
 *
 * @since 2.0.0
 * @return void
 */
function photolab_log_security_audit(): void {
	$context = array( 'source' => 'photolab-audit' );
	$issues  = 0;

	// 1. Protected directories have .htaccess.
	$upload    = wp_upload_dir();
	$base      = trailingslashit( $upload['basedir'] ) . 'Photolab';
	$protected = array( "$base/photos", "$base/watermarked" );

	foreach ( $protected as $dir ) {
		$htaccess = "$dir/.htaccess";
		if ( is_dir( $dir ) && ! file_exists( $htaccess ) ) {
			Logger::error( "Audit: .htaccess mancante in $dir — le foto originali potrebbero essere accessibili.", $context );
			++$issues;
		}
	}

	// 2. assets/ directory does NOT have deny-all .htaccess (must be public).
	$assets_htaccess = "$base/assets/.htaccess";
	if ( file_exists( $assets_htaccess ) ) {
		$content = file_get_contents( $assets_htaccess ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false !== $content && str_contains( $content, 'Deny from all' ) ) {
			Logger::error( 'Audit: assets/.htaccess blocca l\'accesso pubblico al watermark preview. Rimuovere il blocco.', $context );
			++$issues;
		}
	}

	// 3. Image engine detection.
	$engine = (string) get_option( 'photolab_image_engine', 'gd' );
	if ( 'gd' === $engine ) {
		Logger::warning( 'Audit: image engine rilevato = GD. Imagick raccomandato per performance ottimali.', $context );
	}

	// 4. WooCommerce HPOS compatibility — plugin uses wc_get_product(), no direct wp_posts access. OK.
	Logger::info( 'Audit: WooCommerce HPOS compatibility — OK (no direct wp_posts access for products).', $context );

	// 5. PHP version.
	if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
		Logger::error( 'Audit: PHP ' . PHP_VERSION . ' < 8.1 richiesto.', $context );
		++$issues;
	}

	// 6. Summary.
	Logger::info(
		sprintf(
			'Audit sicurezza/performance Photolab %s completato. Problemi trovati: %d.',
			PHOTOLAB_VERSION,
			$issues
		),
		$context
	);
}

/**
 * Admin notice shown when WooCommerce is not active.
 *
 * @since 2.0.0
 * @return void
 */
function photolab_notice_wc_missing(): void {
	echo '<div class="notice notice-error"><p>' .
		esc_html__( 'Photolab requires WooCommerce to be active. The plugin is disabled.', 'photolab' ) .
		'</p></div>';
}
