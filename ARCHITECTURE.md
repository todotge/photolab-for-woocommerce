# Photolab Architecture

> Version 0.0.5 — DB schema 1.0.0  
> PHP 8.1+, WordPress 6.5+, WooCommerce 8.0+  
> Imagick (preferred) / GD (fallback)

---

## Overview

Photolab is a WordPress + WooCommerce plugin for bulk photo album management and sales.  
It handles upload, watermarking, thumbnail generation, product creation, expiration, and cleanup — all through a SPA admin panel with zero page reloads.

---

## Directory Layout

```
photolab/
├── photolab.php                  # Plugin bootstrap, hooks registration
├── readme.txt                    # WP.org plugin readme
├── ARCHITECTURE.md               # This file
├── INSTRUCTIONS.md               # Setup & usage
├── FLOWS.md                      # Process flows
├── AGENTS.md                     # AI agent instructions
├── CLAUDE.md                     # AI assistant instructions
├── INFO_PER_AI_CONNECTION.md     # Quick reference for AI tools
├── ANALISI_COMPLETA_EVENTI.md    # Race condition analysis
├── FASI.md / STEP_FASI.md        # Development phase docs
│
├── includes/
│   ├── class-activator.php        # Plugin activation/deactivation
│   ├── class-admin.php            # Admin menu, enqueue, settings
│   ├── class-admin-notices.php    # Dismissible admin notices
│   ├── class-cleanup-scheduler.php # Daily cleanup, watermark recovery, dead letter
│   ├── class-database.php         # DB schema, migrations, indexes
│   ├── class-download-guard.php   # Blocks downloads of non-watermarked photos
│   ├── class-lock.php             # Distributed lock (MySQL GET_LOCK / transient)
│   ├── class-logger.php           # Structured logger via wc_get_logger
│   ├── class-recovery-scheduler.php # Aborts stale uploads, re-enqueues stuck
│   ├── class-state-machine.php    # FSM for album + photo transitions
│   ├── class-watermark-job.php    # Async watermark batch worker (legacy, kept for generate_thumbnail_meta)
│   ├── class-watermark-processor.php # Image compositing (Imagick/GD/CLI)
│   └── rest/
│       ├── class-upload-controller.php    # /upload/start, /chunk, /complete, /status
│       ├── class-heartbeat-controller.php # /upload/heartbeat
│       ├── class-album-controller.php     # /albums CRUD, reset
│       ├── class-photo-controller.php     # /photos/watermark-status
│       ├── class-watermark-controller.php # /watermark CRUD, position
│       └── class-settings-controller.php  # /settings GET
│
├── templates/
│   └── admin-page.php           # SPA admin HTML (Tailwind CSS)
│
├── assets/
│   ├── css/admin.css             # Compiled Tailwind CSS
│   ├── js/admin.js               # Vanilla JS SPA controller
│   └── icon.svg                  # Menu icon (base64 data URI in PHP)
│
├── languages/
│   └── photolab.pot              # Translation template
│
├── tests/
│   ├── bootstrap.php             # PHPUnit bootstrap + WP stubs
│   └── Unit/
│       ├── StateMachineTest.php  # FSM transition tests
│       └── WatermarkJobTest.php  # Thumbnail generation tests
│
├── vendor/                       # Composer deps (PHPUnit, PHPCS)
└── node_modules/                 # NPM deps (Tailwind CLI)
```

---

## Database Schema

Tables are created with `$wpdb->prefix . 'Photolab_'` prefix (e.g. `wp_Photolab_albums`).

### `wp_Photolab_albums`

| Column | Type | Description |
|--------|------|-------------|
| `id` | mediumint(9) PK AI | Album ID |
| `album_name` | varchar(255) UNIQUE | Display name |
| `term_id` | bigint(20) | WC product_cat term ID |
| `user_id` | bigint(20) | Owner user ID |
| `status` | varchar(20) | FSM state: idle, uploading, watermarking, aborted, deleting |
| `watermark_snapshot` | varchar(500) | Path to watermark copy during batch |
| `expiration_date` | datetime | Expiry date (all photos) |
| `upload_started_at` | datetime | Upload start timestamp |
| `last_heartbeat` | datetime | Last client ping |
| `aborted_at` | datetime | When recovery cron aborted |
| `created_at` | datetime DEFAULT CURRENT_TIMESTAMP | Row creation |

Indexes: `album_name` (UNIQUE), `status`, `user_id`

### `wp_Photolab_photos`

| Column | Type | Description |
|--------|------|-------------|
| `id` | mediumint(9) PK AI | Photo ID |
| `album_id` | mediumint(9) | FK → albums.id |
| `album_name` | varchar(255) | Album name (denormalized) |
| `photo_name` | varchar(255) | Display name |
| `photo_price` | decimal(10,2) | Unit price |
| `file_url` | varchar(500) | Original file URL (protected) |
| `watermark_url` | varchar(500) | Watermarked file URL (public) |
| `file_hash` | varchar(64) | SHA256 (dedup within album) |
| `expiration_date` | datetime | Per-photo expiration |
| `published` | tinyint(1) DEFAULT 1 | Published flag |
| `wc_product_id` | bigint(20) | WC product ID |
| `attachment_id` | bigint(20) | Media Library ID |
| `photo_status` | varchar(20) | FSM state: uploaded, watermarking, watermarked, failed, deleted |
| `retry_count` | tinyint(3) | Watermark retry counter |
| `updated_at` | timestamp | Auto-updated on change |

Indexes: `(file_hash, album_name)` UNIQUE, `album_name`, `published`, `photo_status`

---

## File System Layout

```
wp-content/uploads/Photolab/
├── assets/
│   ├── watermark.png               # Active watermark (public for preview)
│   └── watermark_<timestamp>.png   # Snapshot during batch (temp)
├── photos/<Album>/                 # Original files (protected via .htaccess)
└── watermarked/<Album>/            # Watermarked files (public)
```

The `photos/` directory has a `.htaccess` with `Deny from all` to protect original files.  
Customers download originals via WooCommerce download links (stored in product metadata).

---

## State Machine

All album and photo status transitions are governed by `State_Machine` with Compare-And-Swap (CAS) semantics.  
Each transition is validated against an explicit graph before execution.

### Album States

```
idle ──→ uploading ──→ watermarking ──→ idle
  ↑          │               │
  │          ↓               ↓
  │       aborted ←───── aborted
  │          │
  └────── deleting ──→ aborted
```

| From | To | Trigger |
|------|----|---------|
| idle | uploading | POST /upload/start |
| idle | deleting | POST /albums/{id}/delete |
| uploading | watermarking | First chunk with photos (→ inline watermark) |
| uploading | idle | POST /upload/complete |
| uploading | aborted | Recovery cron (5 min no heartbeat) |
| watermarking | idle | All photos terminal (auto-settle) |
| watermarking | aborted | Recovery cron (when stuck) |
| aborted | idle | POST /albums/{id}/reset |
| aborted | deleting | POST /albums/{id}/delete |

### Photo States

```
uploaded ──→ watermarking ──→ watermarked
  │               │
  ↓               ↓
failed ←──── watermarking
```

| From | To | Trigger |
|------|----|---------|
| uploaded | watermarking | Inline watermark during chunk |
| watermarking | watermarked | Inline watermark success |
| watermarking | failed | Watermark processing error |
| uploaded | failed | Error before watermark (inline) |
| failed | uploaded | retrigger_failed_photos (recovery cron) |
| uploaded | deleted | Album deletion |
| watermarked | deleted | Album deletion |

---

## REST API

All endpoints protected by `current_user_can('manage_options')` + `X-WP-Nonce` header.

| Method | Endpoint | Purpose | Inline Watermark |
|--------|----------|---------|-----------------|
| POST | `/upload/start` | Create album, snapshot watermark, return job_id | — |
| POST | `/upload/chunk` | Upload N files, create products + watermarks inline | ✅ Yes |
| GET | `/upload/status` | Upload progress (processed count) | — |
| POST | `/upload/complete` | Finalize album, cleanup snapshot | — |
| POST | `/upload/heartbeat` | Client liveness ping (30s) | — |
| GET | `/albums` | Paginated album list | — |
| DELETE | `/albums/{id}` | Delete album + products + photos | — |
| POST | `/albums/{id}/reset` | Reset aborted → idle | — |
| GET | `/photos/watermark-status` | Watermark progress per album | — |
| POST | `/watermark` | Upload/replace watermark | — |
| POST | `/watermark/position` | Update position only | — |
| DELETE | `/watermark` | Remove watermark | — |
| GET | `/settings` | Global settings | — |

---

## Security

### Nonce Verification
- REST API nonce via `X-WP-Nonce` header (WordPress core verifies before callback)
- JS sends nonce on every `apiFetch()` call via `wp_localize_script`

### Capability Check
- Every REST endpoint checks `current_user_can('manage_options')`
- Only administrators can upload, delete, or configure

### File Upload Safety
- `wp_check_filetype_and_ext()` validates MIME on every upload
- `wp_handle_upload()` moves files (no direct `move_uploaded_file` except watermark)
- SHA256 dedup prevents same-file duplicates within album

### Original File Protection
- `.htaccess` with `Deny from all` in `photos/<Album>/` (Apache)
- Nginx users must add equivalent `deny all;` rule
- Watermarked copies are public (served as product featured images)

### Download Security
- WooCommerce download links point to original file URLs
- `Download_Guard` checks `photo_status` before allowing download
- Non-watermarked photos return 403

### Watermark Snapshot Security
- Snapshot path validated with `realpath()` + `str_starts_with()` before `unlink()`
- Only files inside `uploads/Photolab/assets/` can be deleted

---

## Performance

### Inline Watermark
Watermark is applied synchronously during the chunk upload request (not async).

### Resize Before Watermark
Images are resized to max 1200px width before watermarking.  
Original files remain full resolution for download.  
Watermarked file is only displayed in a ~1200px box on frontend.

### Thumbnail Generation
Only `woocommerce_thumbnail` is generated (via `wp_get_image_editor`).  
Full-size watermarked file serves all other contexts (zoom, lightbox).

### Batch Size
Chunk upload processes 5 files per request.  
Each file has `set_time_limit(120)`.

### Recovery
Recovery cron runs every 5 minutes.  
Re-enqueues all `uploaded`/`failed` photos on `watermarking` albums.  
CAS guards prevent duplicate processing.

---

## Key Classes

| Class | Responsibility |
|-------|---------------|
| `Upload_Controller` | Upload pipeline: start → chunk × N → complete |
| `State_Machine` | FSM for album/photo transitions (CAS) |
| `Watermark_Processor` | Image compositing (Imagick, GD, CLI) |
| `Watermark_Job` | `generate_thumbnail_meta()`, legacy AS worker |
| `Album_Controller` | Album CRUD, delete, reset |
| `Heartbeat_Controller` | Client liveness |
| `Photo_Controller` | Watermark progress status |
| `Watermark_Controller` | Watermark file upload/delete |
| `Settings_Controller` | Global settings GET |
| `Cleanup_Scheduler` | ⏰ Daily cleanup, watermark recovery, dead letter |
| `Recovery_Scheduler` | ⏰ Stuck upload abort, photo re-enqueue |
| `Database` | Schema, migrations (idempotent) |
| `Lock` | Distributed lock (MySQL + transient) |
| `Logger` | Structured logging via `wc_get_logger` |
| `Download_Guard` | ⛔ Download block for non-watermarked |
| `Activator` | Activation checks, .htaccess creation |
| `Admin` | Menu, enqueue, admin page |

---

## Dependencies

| Package | Use |
|---------|-----|
| WordPress 6.5+ | REST API, Media Library, nonce |
| WooCommerce 8.0+ | Products, downloads, image sizes |
| Action Scheduler (bundled with WC) | Recurring tasks (recovery, cleanup) |
| Imagick / GD | Image processing |
| MySQL 8.0+ / MariaDB 10.6+ | Database |
| PHP 8.1+ | Union types, match, str_contains |

---

## CI & Testing

- **CI**: GitHub Actions on PHP 8.1/8.2/8.3 + Tailwind build
- **PHPUnit**: 9 tests (StateMachine + WatermarkJob)
- **PHPCS**: WordPress Coding Standards (0 errors with `--warning-severity=0`)
- **Composer**: `composer run test`, `composer run phpcs`

---

## Expired Photo Cleanup

`Cleanup_Scheduler::run_daily_cleanup()` runs hourly via AS and:

1. Finds photos where `expiration_date < NOW()` AND `published = 1`
2. For each photo: removes WC product, deletes attachment, sets `published = 0`
3. Cleans up orphaned watermark snapshot files
4. Removes stale Action Scheduler jobs stuck in `in-progress` > 1h

---

## Dead Letter Queue

Photos that fail watermark >5 times (`PHOTO_RETRY_LIMIT`) are:
- Admin notified via email
- No longer retried
- Deleted after 7 days (WC product + DB row)
