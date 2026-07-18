<?php
/**
 * Activation and deactivation hooks for Photolab.
 *
 * @package Photolab
 */

namespace Photolab;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles plugin activation, deactivation, and system requirement checks.
 */
class Activator {

	/**
	 * .htaccess content applied to protected directories.
	 *
	 * Denies all direct HTTP access to original and watermarked photos.
	 *
	 * @var string
	 */
	const HTACCESS_CONTENT = "Options -Indexes\n<FilesMatch \".*\">\n  Order Allow,Deny\n  Deny from all\n</FilesMatch>";

	/**
	 * Run on plugin activation.
	 *
	 * Verifies system requirements, installs the DB schema, creates upload
	 * directories, and writes .htaccess protection files.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public static function activate(): void {
		Logger::info( 'Activator::activate() — avvio attivazione plugin.', array( 'source' => 'photolab-activator' ) );

		// 1. Check requirements — bail with deactivation if critical ones fail.
		$errors = self::check_requirements();

		if ( ! empty( $errors ) ) {
			// Store error messages so admin_notices can display them.
			set_transient( 'photolab_activation_errors', $errors, 60 );

			add_action( 'admin_notices', array( static::class, 'show_activation_errors' ) );

			Logger::error(
				'Activator::activate() — requisiti mancanti: ' . implode( '; ', $errors ),
				array( 'source' => 'photolab-activator' )
			);

			// Deactivate the plugin without triggering the deactivation hook.
			deactivate_plugins( plugin_basename( PHOTOLAB_PLUGIN_FILE ) );

			// wp_die with back link so the user gets feedback in the browser.
			wp_die(
				'<strong>' . esc_html__( 'Photolab could not be activated:', 'photolab' ) . '</strong><br>' .
				implode( '<br>', array_map( 'esc_html', $errors ) ) .
				'<br><a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">&laquo; ' . esc_html__( 'Torna ai plugin', 'photolab' ) . '</a>',
				esc_html__( 'Photolab — Requisiti mancanti', 'photolab' ),
				array( 'back_link' => false )
			);
		}

		// 2. Install / upgrade DB schema.
		try {
			$db = new Database();
			$db->install();
		} catch ( \Throwable $e ) {
			Logger::error(
				'Activator::activate() — errore install DB: ' . $e->getMessage(),
				array( 'source' => 'photolab-activator' )
			);
			wp_die(
				esc_html( 'Photolab — errore creazione tabelle DB: ' . $e->getMessage() ),
				'Photolab — Errore DB',
				array( 'back_link' => true )
			);
		}

		// 3. Create upload directories.
		self::create_directories();

		// 4. Flush rewrite rules.
		flush_rewrite_rules();

		Logger::info( 'Activator::activate() — attivazione completata.', array( 'source' => 'photolab-activator' ) );
	}

	/**
	 * Run on plugin deactivation.
	 *
	 * Flushes rewrite rules and removes transient/temporary options.
	 * Does NOT delete user data or DB tables.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		Logger::info( 'Activator::deactivate() — avvio disattivazione plugin.', array( 'source' => 'photolab-activator' ) );

		flush_rewrite_rules();

		// Remove transient options written during activation or runtime.
		delete_transient( 'photolab_activation_errors' );
		delete_option( 'photolab_image_engine' );

		// Clear any persistent admin notices.
		Admin_Notices::clear_all();

		Logger::info( 'Activator::deactivate() — disattivazione completata.', array( 'source' => 'photolab-activator' ) );
	}

	/**
	 * Display activation error admin notices stored in a transient.
	 *
	 * @return void
	 */
	public static function show_activation_errors(): void {
		$errors = get_transient( 'photolab_activation_errors' );
		if ( empty( $errors ) || ! is_array( $errors ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>Photolab non può essere attivato:</strong></p><ul>';
		foreach ( $errors as $error ) {
			echo '<li>' . esc_html( $error ) . '</li>';
		}
		echo '</ul></div>';

		delete_transient( 'photolab_activation_errors' );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Check all minimum system requirements.
	 *
	 * @return string[] Array of human-readable error strings. Empty = all OK.
	 */
	private static function check_requirements(): array {
		$errors = array();

		Logger::info( 'Activator::check_requirements() — verifica requisiti.', array( 'source' => 'photolab-activator' ) );

		// PHP version.
		if ( version_compare( PHP_VERSION, PHOTOLAB_MIN_PHP, '<' ) ) {
			$errors[] = sprintf(
				'PHP %s+ richiesto. Versione attuale: %s.',
				PHOTOLAB_MIN_PHP,
				PHP_VERSION
			);
		}

		// WordPress version.
		if ( version_compare( get_bloginfo( 'version' ), PHOTOLAB_MIN_WP, '<' ) ) {
			$errors[] = sprintf(
				'WordPress %s+ richiesto. Versione attuale: %s.',
				PHOTOLAB_MIN_WP,
				get_bloginfo( 'version' )
			);
		}

		// WooCommerce active + version.
		if ( ! class_exists( 'WooCommerce' ) ) {
			$errors[] = 'WooCommerce deve essere installato e attivo.';
		} elseif ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, PHOTOLAB_MIN_WC, '<' ) ) {
			$errors[] = sprintf(
				'WooCommerce %s+ richiesto. Versione attuale: %s.',
				PHOTOLAB_MIN_WC,
				WC_VERSION
			);
		}

		// Image editor: Imagick preferred, GD as fallback.
		$has_imagick = extension_loaded( 'imagick' );
		$has_gd      = extension_loaded( 'gd' );

		if ( ! $has_imagick && ! $has_gd ) {
			$errors[] = 'Estensione PHP Imagick o GD richiesta per elaborazione immagini.';
		} elseif ( ! $has_imagick && $has_gd ) {
			// Not a blocking error but log a warning and set a transient for the
			// admin notice shown after activation.
			set_transient( 'photolab_gd_only_warning', true, 0 );
			Logger::warning(
				'Activator::check_requirements() — solo GD disponibile. Imagick mancante. Performance ridotte.',
				array( 'source' => 'photolab-activator' )
			);
		}

		// memory_limit.
		$memory_limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		if ( $memory_limit > 0 && $memory_limit < 256 * MB_IN_BYTES ) {
			$errors[] = sprintf(
				'memory_limit PHP ≥ 256MB richiesto. Attuale: %s.',
				ini_get( 'memory_limit' )
			);
		}

		// max_execution_time (0 = unlimited — acceptable).
		$max_exec = (int) ini_get( 'max_execution_time' );
		if ( $max_exec > 0 && $max_exec < 60 ) {
			$errors[] = sprintf(
				'max_execution_time PHP ≥ 60s richiesto. Attuale: %ds.',
				$max_exec
			);
		}

		// upload_max_filesize.
		$upload_max = wp_convert_hr_to_bytes( ini_get( 'upload_max_filesize' ) );
		if ( $upload_max > 0 && $upload_max < 20 * MB_IN_BYTES ) {
			$errors[] = sprintf(
				'upload_max_filesize PHP ≥ 20MB richiesto. Attuale: %s.',
				ini_get( 'upload_max_filesize' )
			);
		}

		// post_max_size.
		$post_max = wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) );
		if ( $post_max > 0 && $post_max < 100 * MB_IN_BYTES ) {
			$errors[] = sprintf(
				'post_max_size PHP ≥ 100MB richiesto. Attuale: %s.',
				ini_get( 'post_max_size' )
			);
		}

		if ( empty( $errors ) ) {
			Logger::info( 'Activator::check_requirements() — tutti i requisiti soddisfatti.', array( 'source' => 'photolab-activator' ) );
		}

		return $errors;
	}

	/**
	 * Create the Photolab upload directory tree and write .htaccess protection.
	 *
	 * Directory layout:
	 *   uploads/Photolab/
	 *   ├── assets/
	 *   ├── photos/         ← .htaccess deny-all (originals, never public)
	 *   └── watermarked/    ← public (featured images served to customers)
	 *
	 * Failures on directory creation or .htaccess writing are stored as
	 * persistent admin notices via Admin_Notices.
	 *
	 * @return void
	 */
	private static function create_directories(): void {
		$upload   = wp_upload_dir();
		$base_dir = trailingslashit( $upload['basedir'] ) . 'Photolab';

		$dirs = array(
			$base_dir . '/assets',
			$base_dir . '/photos',
			$base_dir . '/watermarked',
		);

		foreach ( $dirs as $dir ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				$message = sprintf( 'Directory non creabile: %s — verifica i permessi del filesystem.', $dir );

				Logger::error(
					'Activator::create_directories() — ' . $message,
					array( 'source' => 'photolab-activator' )
				);

				Admin_Notices::add(
					'dir-' . sanitize_key( basename( $dir ) ),
					$message,
					'error'
				);
			} else {
				Logger::info(
					sprintf( 'Activator::create_directories() — directory OK: %s', $dir ),
					array( 'source' => 'photolab-activator' )
				);
			}
		}

		// Write .htaccess only in photos/ — originals must stay protected.
		// watermarked/ is intentionally public: these are the featured images
		// served to customers and must be accessible via HTTP.
		self::write_htaccess( $base_dir . '/photos' );
	}

	/**
	 * Write the deny-all .htaccess file into the given directory.
	 *
	 * If the file already exists it is overwritten so the content is always
	 * authoritative on re-activation.
	 *
	 * Failures are stored as persistent admin notices via Admin_Notices.
	 *
	 * @param string $dir Absolute path to the directory.
	 * @return void
	 */
	private static function write_htaccess( string $dir ): void {
		$htaccess_path = trailingslashit( $dir ) . '.htaccess';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$bytes = file_put_contents( $htaccess_path, self::HTACCESS_CONTENT );

		if ( false === $bytes ) {
			$message = sprintf( 'Impossibile scrivere .htaccess in: %s — le foto originali potrebbero essere accessibili pubblicamente.', $dir );

			Logger::error(
				'Activator::write_htaccess() — ' . $message,
				array( 'source' => 'photolab-activator' )
			);

			Admin_Notices::add(
				'htaccess-' . sanitize_key( basename( $dir ) ),
				$message,
				'error'
			);
		} else {
			Logger::info(
				sprintf( 'Activator::write_htaccess() — .htaccess scritto in: %s (%d bytes)', $htaccess_path, $bytes ),
				array( 'source' => 'photolab-activator' )
			);
		}
	}
}
