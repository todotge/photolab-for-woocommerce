<?php
/**
 * Persistent admin notice system for Photolab critical errors.
 *
 * Errors are stored in the photolab_critical_errors option (array of
 * associative arrays). A dismiss AJAX action clears individual notices.
 *
 * @package Photolab
 */

namespace Photolab;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages persistent admin notices for errors that block Photolab functionality.
 *
 * Usage:
 *   Admin_Notices::add( 'error-slug', 'Something broke.' );
 *   Admin_Notices::add( 'gd-warning', 'GD only.', 'warning' );
 */
class Admin_Notices {

	/**
	 * WordPress option key that stores the persistent notices array.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'photolab_critical_errors';

	/**
	 * AJAX action name used to dismiss a single notice.
	 *
	 * @var string
	 */
	const AJAX_ACTION = 'photolab_dismiss_notice';

	/**
	 * Register WordPress hooks.
	 *
	 * Called once in Admin::init() so hooks are wired up on every admin request.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_notices', array( static::class, 'render' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( static::class, 'handle_dismiss' ) );
	}

	/**
	 * Persist a new admin notice.
	 *
	 * If a notice with the same $slug already exists it is replaced, preventing
	 * duplicates across repeated plugin boots.
	 *
	 * @param string $slug    Machine-readable unique key (e.g. 'dir-not-writable').
	 * @param string $message Human-readable message (not escaped — escape before display).
	 * @param string $type    Notice CSS type: 'error', 'warning', 'success', 'info'. Default 'error'.
	 * @return void
	 */
	public static function add( string $slug, string $message, string $type = 'error' ): void {
		$notices = self::get_all();

		$notices[ $slug ] = array(
			'message' => $message,
			'type'    => in_array( $type, array( 'error', 'warning', 'success', 'info' ), true ) ? $type : 'error',
		);

		update_option( self::OPTION_KEY, $notices, false );
	}

	/**
	 * Remove a previously stored notice by slug.
	 *
	 * @param string $slug Notice slug to remove.
	 * @return void
	 */
	public static function remove( string $slug ): void {
		$notices = self::get_all();

		if ( isset( $notices[ $slug ] ) ) {
			unset( $notices[ $slug ] );
			update_option( self::OPTION_KEY, $notices, false );
		}
	}

	/**
	 * Remove all stored notices.
	 *
	 * @return void
	 */
	public static function clear_all(): void {
		delete_option( self::OPTION_KEY );
	}

	/**
	 * Render all stored notices in the WordPress admin area.
	 *
	 * Hooked to admin_notices. Outputs standard WP dismissible notice markup
	 * and inline JS to send the AJAX dismiss request.
	 *
	 * @return void
	 */
	public static function render(): void {
		$notices = self::get_all();

		if ( empty( $notices ) ) {
			return;
		}

		$nonce = wp_create_nonce( self::AJAX_ACTION );

		foreach ( $notices as $slug => $notice ) {
			$type    = esc_attr( $notice['type'] );
			$message = esc_html( $notice['message'] );
			$slug    = esc_attr( $slug );

			printf(
				'<div class="notice notice-%s is-dismissible photolab-notice" data-slug="%s" data-nonce="%s">
					<p><strong>Photolab:</strong> %s</p>
				</div>',
				esc_attr( $type ),
				esc_attr( $slug ),
				esc_attr( $nonce ),
				esc_html( $message )
			);
		}

		// Inline JS — send AJAX dismiss on WP's native dismiss button click.
		?>
		<script>
		(function () {
			document.addEventListener('click', function (e) {
				var btn = e.target.closest('.photolab-notice .notice-dismiss');
				if (!btn) return;

				var notice = btn.closest('.photolab-notice');
				if (!notice) return;

				var data = new FormData();
				data.append('action', '<?php echo esc_js( self::AJAX_ACTION ); ?>');
				data.append('slug', notice.dataset.slug);
				data.append('nonce', notice.dataset.nonce);

				fetch(ajaxurl, { method: 'POST', body: data });
			});
		}());
		</script>
		<?php
	}

	/**
	 * AJAX handler: dismiss a single notice identified by its slug.
	 *
	 * @return void
	 */
	public static function handle_dismiss(): void {
		check_ajax_referer( self::AJAX_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}

		$slug = isset( $_POST['slug'] ) ? sanitize_key( $_POST['slug'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated

		if ( '' !== $slug ) {
			self::remove( $slug );
		}

		wp_die();
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Return all stored notices as an associative array keyed by slug.
	 *
	 * @return array<string, array{message: string, type: string}>
	 */
	private static function get_all(): array {
		$stored = get_option( self::OPTION_KEY, array() );

		return is_array( $stored ) ? $stored : array();
	}
}
