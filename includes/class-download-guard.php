<?php
/**
 * Download guard — prevents customers from downloading the original photo
 * before the asynchronous watermark batch has finished.
 *
 * The guard hooks `woocommerce_product_file_download_path` (the canonical
 * filter applied in `WC_Download_Handler::download()` immediately before the
 * file is served). For each downloadable file we look up the matching
 * `wp_Photolab_photos` row by `wc_product_id` and inspect `photo_status`:
 *
 *  - `watermarked`               → allow (return path unchanged)
 *  - `uploaded` / `watermarking` → block with a "still processing" message
 *  - `failed` / `deleted`        → block with a "no longer available" message
 *  - row missing (non-Photolab)  → return path unchanged (filter is a no-op)
 *
 * The query is parametrised via `$wpdb->prepare()`. A single `LIMIT 1` is
 * fine because Photolab guarantees one product per photo row at INSERT time.
 *
 * @package Photolab
 * @since   2.0.0
 */

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared


namespace Photolab;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Photolab\Download_Guard — hooks into the WooCommerce download pipeline
 * to enforce that only watermarked photos can be downloaded.
 *
 * @since 2.0.0
 */
class Download_Guard {

	/**
	 * Logger source for download-guard events.
	 *
	 * @var string
	 */
	const SOURCE = 'photolab-download-guard';

	/**
	 * Register the WooCommerce filter hook.
	 *
	 * `woocommerce_product_file_download_path` is the filter triggered by
	 * `WC_Download_Handler::download_product()` after permission checks have
	 * already validated the customer's order. We inspect the file path that
	 * is about to be served and short-circuit with `wp_die()` if the matching
	 * Photolab row is not yet in `watermarked` state.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter(
			'woocommerce_product_file_download_path',
			array( __CLASS__, 'filter_download_path' ),
			10,
			3
		);
	}

	/**
	 * Filter callback for `woocommerce_product_file_download_path`.
	 *
	 * Signature matches WooCommerce: ( string $file_path, WC_Product $product,
	 * string $download_id ). Non-Photolab products fall through unchanged so
	 * other plugins selling downloadable products are not affected.
	 *
	 * @since 2.0.0
	 *
	 * @param string $file_path   File path or URL about to be served.
	 * @param mixed  $product     Product instance (WC_Product) or null in edge cases.
	 * @param string $download_id Internal WC download token.
	 * @return string The original file path when the download is allowed; never
	 *                returns when the download is blocked because `wp_die()`
	 *                terminates the request.
	 */
	public static function filter_download_path( string $file_path, $product, string $download_id ): string {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return $file_path;
		}

		$product_id = (int) $product->get_id();
		if ( $product_id <= 0 ) {
			return $file_path;
		}

		$status = self::lookup_photo_status( $product_id );

		if ( null === $status ) {
			// Not a Photolab product — let the download proceed untouched.
			return $file_path;
		}

		switch ( $status ) {
			case State_Machine::PHOTO_WATERMARKED:
				// Allow — featured image-protected file is ready.
				return $file_path;

			case State_Machine::PHOTO_UPLOADED:
			case State_Machine::PHOTO_WATERMARKING:
				Logger::info(
					sprintf(
						'Download_Guard::filter_download_path — bloccato product_id=%d photo_status=%s download_id=%s.',
						$product_id,
						$status,
						sanitize_key( $download_id )
					),
					array( 'source' => self::SOURCE )
				);
				wp_die(
					esc_html__( 'This photo is still being processed. Please check back in a moment.', 'photolab' ),
					esc_html__( 'Photo not ready', 'photolab' ),
					array( 'response' => 425 )
				);
				// Unreachable.
				return $file_path;

			case State_Machine::PHOTO_FAILED:
			case State_Machine::PHOTO_DELETED:
				Logger::warning(
					sprintf(
						'Download_Guard::filter_download_path — non disponibile product_id=%d photo_status=%s download_id=%s.',
						$product_id,
						$status,
						sanitize_key( $download_id )
					),
					array( 'source' => self::SOURCE )
				);
				wp_die(
					esc_html__( 'This photo is no longer available.', 'photolab' ),
					esc_html__( 'Photo unavailable', 'photolab' ),
					array( 'response' => 410 )
				);
				// Unreachable.
				return $file_path;

			default:
				// Unknown status — fail closed.
				Logger::warning(
					sprintf(
						'Download_Guard::filter_download_path — stato sconosciuto product_id=%d photo_status=%s.',
						$product_id,
						$status
					),
					array( 'source' => self::SOURCE )
				);
				wp_die(
					esc_html__( 'This photo is no longer available.', 'photolab' ),
					esc_html__( 'Photo unavailable', 'photolab' ),
					array( 'response' => 410 )
				);
				// Unreachable.
				return $file_path;
		}
	}

	/**
	 * Look up `photo_status` for a WooCommerce product id.
	 *
	 * Returns the status string when the product belongs to Photolab,
	 * `null` when no matching row exists (non-Photolab product).
	 *
	 * @since 2.0.0
	 *
	 * @param int $product_id WooCommerce product id.
	 * @return string|null Photo status or null when not a Photolab photo.
	 */
	private static function lookup_photo_status( int $product_id ): ?string {
		global $wpdb;

		$photos_table = $wpdb->prefix . 'Photolab_photos';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$status = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT photo_status FROM `{$photos_table}` WHERE wc_product_id = %d LIMIT 1",
				$product_id
			)
		);

		if ( '' !== (string) $wpdb->last_error ) {
			Logger::error(
				sprintf(
					'Download_Guard::lookup_photo_status — SQL error product_id=%d: %s',
					$product_id,
					$wpdb->last_error
				),
				array( 'source' => self::SOURCE )
			);
			// Fail open on DB error so legitimate downloads are not blocked
			// by infrastructure outages — log loudly so ops notices.
			return null;
		}

		if ( null === $status ) {
			return null;
		}

		return (string) $status;
	}
}



// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared