# Photolab for WooCommerce

**Bulk photo album management and sales plugin for WordPress + WooCommerce**

Photolab streamlines selling digital photos at scale. Upload thousands of photos in batches, apply watermarks inline, and publish each as a downloadable WooCommerce product — all from a single admin panel. Built for event photographers, photo studios, and anyone selling large galleries online.

---

## Features

### Photo Management
- **Bulk upload** — chunked uploads (5 files per chunk), supports 2,000+ photos per album
- **Inline watermarking** — watermark applied during upload, no background queue drift; Imagick preferred, GD fallback
- **Album organization** — group photos into albums with price, watermark, and expiration date
- **Deduplication** — SHA-256 hash check prevents duplicate photos within the same album
- **Configurable watermark** — full-width or bottom-right positioning, PNG upload, snapshot at album creation

### WooCommerce Integration
- **Automatic products** — each photo becomes a virtual downloadable WooCommerce product
- **Watermarked previews** — watermarked versions shown publicly; originals protected and delivered only on purchase
- **HPOS compatible** — uses `wc_get_product()`, no direct `wp_posts` access

### Reliability & Concurrency
- **Finite State Machine** — CAS (Compare-And-Swap) transitions for albums and photos; no race conditions
- **Multi-upload** — up to 3 concurrent album uploads per user, with rate limiting (HTTP 429) and duplicate-name rejection (HTTP 409)
- **Recovery** — client heartbeat every 30s; server-side recovery scan aborts stale uploads after 5 min silence; manual reset available
- **Idempotency-Key** — safe chunk retry after network timeouts without reprocessing files
- **Distributed lock** — MySQL `GET_LOCK` / transient fallback for multi-server deployments

### Security & Protection
- **Original file protection** — `.htaccess` `Deny from all` in the photos directory; Nginx rule documented
- **Download guard** — blocks non-watermarked downloads: HTTP 425 during processing, 410 after expiration
- **Dead letter queue** — photos failing watermark >5 times flagged for review, deleted after 7 days

### Automation
- **Scheduled expiration** — expired album photos auto-unpublished via hourly cron
- **Daily cleanup failsafe** — stuck watermark detection, retry budget, orphan scan, log retention
- **Structured logging** — 13 log sources via `wc_get_logger`, context-rich, sensitive-key redaction

---

## Requirements

| Dependency | Minimum |
|-----------|---------|
| PHP | 8.1 |
| WordPress | 6.5 |
| WooCommerce | 8.0 |
| Imagick (recommended) or GD | — |
| memory_limit | 256 MB |
| max_execution_time | 60 s |
| upload_max_filesize | 20 MB |
| post_max_size | 100 MB |

---

## Installation

1. Upload the `photolab` folder to `/wp-content/plugins/`.
2. Activate the plugin from the WordPress Plugins screen.
3. Ensure WooCommerce is installed and active.
4. Verify Pretty Permalinks are enabled (Settings → Permalinks, any structure except "Plain").

### Photo protection on Nginx

The `.htaccess` file protecting original photos is Apache-only. On Nginx, add to your server block:

```nginx
location ~* /wp-content/uploads/Photolab/photos/ {
    deny all;
    return 403;
}
```

---

## Usage

### First-time setup
1. Go to **Photolab** in the WordPress admin sidebar.
2. Upload a watermark PNG under the Watermark section.
3. Choose position: full-width or bottom-right.

### Uploading an album
1. Enter an album name, price per photo, and optional expiration date.
2. Select photos (JPG, JPEG, PNG) — thousands at once.
3. Click **Upload**. Progress updates live; client sends heartbeat every 30s.

### After upload
- Watermarked versions are generated inline during upload.
- Each photo appears as a WooCommerce product in a category matching the album name.
- The Galleries Status table shows all albums with status badges (Idle, Uploading, Processing, Aborted).
- Expired albums are cleaned up automatically.

### Recovery
- If the browser closes or the network drops, the album transitions to **Aborted** after 5 minutes.
- Reset the album from the admin panel to resume, or delete it. Already-uploaded photos remain as valid products.

---

## Architecture

```
├── photolab.php                    # Main plugin file, bootstrap, hooks
├── includes/
│   ├── class-activator.php         # Activation, requirements check, DB schema
│   ├── class-admin.php             # Admin menu, asset enqueue, SPA shell
│   ├── class-admin-notices.php     # Dismissible admin notices
│   ├── class-cleanup-scheduler.php # Expired photo cleanup, daily failsafe
│   ├── class-database.php          # DB schema & idempotent migrations
│   ├── class-download-guard.php    # Blocks non-watermarked downloads
│   ├── class-lock.php              # Distributed lock (MySQL GET_LOCK / transient)
│   ├── class-logger.php            # Structured logging, context, redaction
│   ├── class-recovery-scheduler.php# Aborts stale uploads
│   ├── class-state-machine.php     # FSM with CAS transitions
│   ├── class-watermark-job.php     # Thumbnail generation via wp_get_image_editor
│   ├── class-watermark-processor.php# Imagick/GD watermark compositing
│   └── rest/
│       ├── class-album-controller.php
│       ├── class-heartbeat-controller.php
│       ├── class-photo-controller.php
│       ├── class-settings-controller.php
│       ├── class-upload-controller.php
│       └── class-watermark-controller.php
├── assets/
│   ├── css/admin.css               # Tailwind CSS (local build)
│   ├── js/admin.js                 # Vanilla JS SPA
│   └── icon.svg                    # Admin menu icon
├── templates/
│   └── admin-page.php              # SPA HTML shell
├── languages/
│   └── photolab.pot                # Translation template
└── tests/
    ├── Unit/                       # 19 unit test files
    └── Integration/                # 11 integration test files
```

### REST API Endpoints

| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/photolab/v1/upload/start` | Initialize album upload |
| POST | `/photolab/v1/upload/chunk` | Upload a chunk of photos |
| POST | `/photolab/v1/upload/complete` | Finalize upload |
| GET | `/photolab/v1/upload/status` | Upload progress |
| POST | `/photolab/v1/upload/heartbeat` | Client liveness ping |
| GET | `/photolab/v1/albums` | List all albums |
| DELETE | `/photolab/v1/albums/{id}` | Delete album + products + files |
| POST | `/photolab/v1/albums/{id}/reset` | Reset aborted album |
| GET | `/photolab/v1/photos/watermark-status` | Watermark progress per album |
| POST | `/photolab/v1/watermark` | Upload watermark PNG |
| DELETE | `/photolab/v1/watermark` | Remove watermark |
| POST | `/photolab/v1/watermark/position` | Set watermark position |
| GET | `/photolab/v1/settings` | Plugin configuration |

---

## Development

### Setup

```bash
composer install
npm ci && npm run build:css
```

### Quality checks

```bash
composer run test          # PHPUnit (unit + integration)
composer run phpcs         # WordPress coding standards
composer run phpstan       # Static analysis level 6
```

### Hooks

**Action Scheduler hooks:** `photolab_cleanup_expired`, `photolab_daily_cleanup`, `photolab_recovery_scan`

**Filters:** `photolab_chunk_size` (default 5), `photolab_use_distributed_lock` (default true), `photolab_watermark_max_retries` (default 5), `photolab_log_retention_days` (default 30), `photolab_log_sensitive_keys`

**Core hooks:** `woocommerce_product_file_download_path` (download guard), `register_activation_hook`, `rest_api_init`

### Logging

Logs at **WooCommerce → Status → Logs**, filtered by `photolab*`. Sources: `photolab`, `photolab-fsm`, `photolab-upload`, `photolab-heartbeat`, `photolab-recovery`, `photolab-watermark-job`, `photolab-rate-limit`, `photolab-ownership`, `photolab-download-guard`, `photolab-idempotency`, `photolab-cleanup`, `photolab-lock`, `photolab-logger`.

---

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines on reporting bugs, requesting features, and submitting pull requests.

---

## License

GNU General Public License v2 or later. See [LICENSE](LICENSE).

---

## Links

- **Plugin URI**: [https://todot.it](https://todot.it)
- **Issues**: [GitHub Issues](https://github.com/todotge/photolab/issues)
- **Documentation**: See `ARCHITECTURE.md`, `FLOWS.md`, and `INSTRUCTIONS.md` in this repository.

---

## Authors

Maintained by [Todot](https://todot.it), Santacruz Foto Lab, and open source contributors.

Built for photographers, by developers.
