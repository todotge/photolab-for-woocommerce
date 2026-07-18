=== Photolab for WooCommerce ===
Contributors: photolabdev
Tags: woocommerce, photos, gallery, watermark, digital downloads, bulk upload, photo sales
Requires at least: 6.5
Tested up to: 6.7
Stable tag: 0.0.5
Requires PHP: 8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bulk photo album management and sales for WooCommerce. Upload thousands of photos, auto-watermark, and sell as downloadable products.

== Description ==

Photolab streamlines selling digital photos at scale through WooCommerce. Upload thousands of photos in batches, apply watermarks inline during upload, and publish each as a downloadable product — all from a single admin panel. Built for event photographers, photo studios, and anyone selling large galleries online.

**Key features:**

* Bulk chunked upload (5 files per chunk, supports 2000+ photos per album)
* Inline watermarking during upload — no background queue drift; Imagick preferred, GD fallback
* Configurable watermark position: full-width or bottom-right
* Each photo becomes a virtual downloadable WooCommerce product
* Original photos protected from public access; customers download only after purchase
* Download blocked until processing completes (HTTP 425 during watermarking, 410 if expired)
* Multi-upload: up to 3 concurrent album uploads per user with rate limiting
* Automatic recovery of interrupted uploads (client heartbeat + server-side cron, FSM with aborted state and manual reset)
* Idempotency-Key on chunk endpoints for safe retry after network timeouts
* Distributed lock (MySQL GET_LOCK / transient fallback) for multi-server deployments
* Daily cleanup failsafe: stuck photo detection, retry budget, orphan scan, log retention
* Structured logging with auto-context (user_id, album_id, photo_id) and sensitive data redaction
* Album expiration management with automatic cleanup
* SHA-256 deduplication to prevent duplicate photos in the same album
* SPA admin panel with zero page reloads, REST API communication
* Imagick support (recommended) with GD fallback for image processing
* WooCommerce HPOS compatible

**Requirements:**

* WordPress 6.5+
* WooCommerce 8.0+
* PHP 8.1+
* PHP Imagick extension (recommended) or GD
* memory_limit >= 256MB
* max_execution_time >= 60s
* upload_max_filesize >= 20MB
* post_max_size >= 100MB

== Installation ==

1. Upload the `photolab` folder to `/wp-content/plugins/`.
2. Activate the plugin from the WordPress Plugins screen.
3. Ensure WooCommerce is installed and active before activating Photolab.
4. Verify Pretty Permalinks are enabled in Settings → Permalinks (any structure except "Plain").

**Original photo protection:**

The `wp-content/uploads/Photolab/photos/` directory is protected via an `.htaccess` file denying direct access. This works on **Apache** servers with `mod_rewrite` enabled.

If using **Nginx**, `.htaccess` files are not read. Add a rule to your server block to protect the directory:

```
location ~* /wp-content/uploads/Photolab/photos/ {
    deny all;
    return 403;
}
```

Consult your hosting provider documentation to apply this configuration.

== Frequently Asked Questions ==

= How many photos can I upload per album? =

The plugin handles high volumes, up to 2000+ photos per album. Uploads use chunks of 5 files (filterable via `photolab_chunk_size`) for compatibility with shared hosting.

= Can I use the plugin without Imagick? =

Yes. If Imagick is unavailable, Photolab automatically uses GD for watermarking. Performance on large volumes will be reduced compared to Imagick.

= Does the watermark affect original photos? =

No. The watermark is applied to a copy (saved in `wp-content/uploads/Photolab/watermarked/`). Originals are stored separately and protected from public access.

= When is the watermark applied? =

Watermarking is inline: it runs during the same HTTP request as the upload chunk. Photos are watermarked immediately as they arrive, not via a background queue. The `/upload/chunk` endpoint returns after processing completes. Download is blocked until the photo reaches the `watermarked` state (HTTP 425).

= What happens if I close the browser during upload? =

The client sends a heartbeat every 30 seconds. If the server receives no heartbeat for 5 minutes (or 10 minutes if none was ever received), a recovery job transitions the album to `aborted`. From the admin panel you can reset the album (`POST /albums/{id}/reset`) to resume, or delete it. Already-uploaded photos remain as valid WooCommerce products.

= Can I upload multiple albums at once? =

Yes, up to 3 concurrent album uploads per user. Attempts beyond this limit return HTTP 429. Albums with the same name already in flight for the same user return HTTP 409.

= What happens if a chunk upload times out? =

The client can retry the same `/upload/chunk` with an `Idempotency-Key` header. The original response is replayed from a transient (TTL 24h) without reprocessing files. Without the header, behavior is unchanged.

= What happens when a photo expires? =

If an expiration date is set for the album, the plugin automatically deletes the WooCommerce product, watermarked image, and database record via a daily job managed by Action Scheduler (bundled with WooCommerce).

= How does the daily cleanup work? =

An Action Scheduler job `photolab_daily_cleanup` runs every 24 hours (default 03:00 UTC) performing a failsafe sweep: detects stuck watermark jobs >1h, re-enqueues stalled photos, retries failed photos within a 5-retry budget per photo, purges expired idempotency transients, scans for disk/DB orphans (log only), and deletes Photolab logs older than 30 days (filter `photolab_log_retention_days`). Action Scheduler logs are auto-deleted after 7 days.

= Does the plugin work in multi-server environments? =

Yes. An optional distributed lock is available: if WordPress uses an external object cache (e.g. Redis), the lock uses `wp_cache_add` (atomic SET NX). Without external object cache, the fallback is a transient. Concurrent chunk attempts for the same album from different nodes return HTTP 423. CAS on the database is the final safeguard. Disable via filter `photolab_use_distributed_lock`.

= Are Pretty Permalinks required? =

Yes. The WordPress REST API requires a permalink structure other than "Plain". The plugin shows an admin notice if Pretty Permalinks are not active.

= Does Photolab work with WooCommerce HPOS? =

Yes. Photolab does not access `wp_posts` directly for WooCommerce products and is fully compatible with High-Performance Order Storage (HPOS).

= Where do I find the logs? =

In **WooCommerce → Status → Logs**, filter by source `photolab*`. Available sources: `photolab`, `photolab-fsm`, `photolab-upload`, `photolab-heartbeat`, `photolab-recovery`, `photolab-watermark-job`, `photolab-rate-limit`, `photolab-ownership`, `photolab-download-guard`, `photolab-idempotency`, `photolab-cleanup`, `photolab-lock`, `photolab-logger`. Each entry automatically includes `user_id`, `album_id`, and other contextual keys. Sensitive data (passwords, tokens, emails) is automatically redacted.

== Changelog ==

= 0.0.5 — Initial public release =

* Bulk chunked upload with inline watermarking (Imagick + GD)
* WooCommerce product auto-creation per photo
* Configurable watermark position (full-width / bottom-right)
* Album expiration with automatic cleanup
* Finite State Machine with CAS transitions
* Upload recovery (heartbeat + server-side scan + manual reset)
* Multi-upload rate limiting (3 concurrent per user)
* Idempotency-Key for safe chunk retry
* Distributed lock for multi-server deployments
* Download guard (HTTP 425 during processing, 410 expired)
* Structured logging with 13 sources via wc_get_logger
* SHA-256 deduplication within albums
* HPOS compatible
* SPA admin panel (vanilla JS + Tailwind CSS local build)
* CI pipeline: PHPStan level 6, PHPCS WordPress, PHPUnit (30 test files)
