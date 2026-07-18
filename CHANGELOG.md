# Changelog

## [0.0.5] — Initial public release

- Bulk photo upload with chunked processing (5 files/chunk)
- Inline watermarking via Imagick (GD fallback)
- WooCommerce product auto-creation per photo
- Configurable watermark position (full-width / bottom-right)
- Album expiration with automatic cleanup
- Finite State Machine with CAS transitions
- Upload recovery (heartbeat + server-side scan + manual reset)
- Multi-upload rate limiting (3 concurrent per user)
- Idempotency-Key for safe chunk retry
- Distributed lock for multi-server deployments
- Download guard (HTTP 425 during processing, 410 expired)
- Structured logging (13 sources via wc_get_logger)
- SHA-256 deduplication within albums
- HPOS compatible
- SPA admin panel (vanilla JS + Tailwind CSS)
- Pretty Permalinks enforcement notice
- WordPress 6.5+, WooCommerce 8.0+, PHP 8.1+
