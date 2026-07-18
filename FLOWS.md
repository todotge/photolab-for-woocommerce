# Photolab — Process Flows

> Detailed step-by-step flow for every major operation.

---

## Upload Flow

```
Browser                     Server REST API                    File System / DB
──────                      ──────────────                    ────────────────

1. User fills form
   (name, price, expiry,
    selects files)
   
2. POST /upload/start
   ──────────────→   ● Validate (album_name, price)
                     ● Rate limit check (max 3 concurrent)
                     ● Create / fetch WC product_cat term
                     ● INSERT/UPDATE album row (status=uploading)
                     ● Snapshot watermark → watermark_<ts>.png
                     ● Return {job_id, term_id}
                     
3. Loop files in chunks of 5:
   POST /upload/chunk
   ──────────────→   ● Status guard (must be uploading/watermarking)
                     ● Distributed lock (60s TTL)
                     ● Foreach file:
                       │
                       ├─ MIME validation (wp_check_filetype_and_ext)
                       ├─ SHA256 dedup check (file_hash + album_name)
                       ├─ wp_handle_upload() → photos/<Album>/orig.jpg
                       ├─ WC_Product_Simple::save() → product_id
                       ├─ INSERT wp_Photolab_photos
                       │   (photo_status='uploaded', product_id)
                       │
                       ├─ INLINE WATERMARK:
                       │  ├─ State_Machine::transition_photo(uploaded→watermarking)
                       │  ├─ Watermark_Processor::apply()
                       │  │   (resize 1200px → compose watermark → save)
                       │  ├─ wp_insert_attachment() → Media Library
                       │  ├─ set_post_thumbnail(product_id, attachment_id)
                       │  ├─ generate_thumbnail_meta(attachment_id)
                       │  │   (only woocommerce_thumbnail)
                       │  └─ transition_photo(watermarking→watermarked)
                       │
                       └─ Returns: photo_id (or error string)
                     
                     ● Restore WC hooks
                     ● Return {processed, total, errors}

4. POST /upload/complete
   ──────────────→   ● Transition album uploading→idle
                     ● Delete watermark snapshot
                     ● Delete price transient

5. Album idle. Done.
```

---

## Watermark Processing (per photo)

```
Original file (photos/<Album>/filename.jpg)
│
├─ Load with Imagick (preferred)
│  ├─ GetImageWidth()
│  ├─ IF > 1200px: resizeImage(1200x, CATROM)
│  ├─ setImageResolution(75, 75)
│  ├─ Load watermark PNG
│  ├─ Position: fullwidth OR bottom_right (30% width, 2% padding)
│  ├─ compositeImage (Imagick::COMPOSITE_OVER)
│  ├─ setImageCompression(JPEG), setImageCompressionQuality(75)
│  ├─ stripImage()
│  └─ writeImage(watermarked/<Album>/filename.jpg)
│
├─ If Imagick fails:
│  ├─ Try ImageMagick CLI (convert)
│  └─ Fallback to GD: imagecreatefromjpeg → copyresampled → imagejpeg
│     ● Memory guard: if estimated > available → return error
│     ● Dynamic quality: 85 (>6MP), 80 (>2MP), 75 (default)
│
├─ wp_insert_attachment() → Media Library
├─ set_post_thumbnail(product_id, attachment_id)
└─ generate_thumbnail_meta():
   ├─ wp_get_image_editor()
   ├─ wc_get_image_size('woocommerce_thumbnail')
   ├─ resize(300×300, crop)
   ├─ save()
   └─ wp_update_attachment_metadata()
```

---

## Album Deletion Flow

```
1. DELETE /albums/{id}
   ├─ Guard: album must be idle or aborted (CAS)
   ├─ Ownership guard: current user must own album
   │
   ├─ FOR each photo in album:
   │   ├─ wp_delete_post(wc_product_id, force=true)
   │   ├─ wp_delete_attachment(attachment_id, force=true)
   │   ├─ $wpdb->delete(wp_Photolab_photos, id)
   │   └─ Logger::info()
   │
   ├─ wp_delete_term(term_id, product_cat)
   ├─ $wpdb->delete(wp_Photolab_albums, id)
   ├─ Delete files:
   │   ├─ rmdir(photos/<Album>/) — via WP_Filesystem
   │   └─ rmdir(watermarked/<Album>/)
   └─ Logger::info()
```

---

## Album Reset Flow (aborted → idle)

```
1. POST /albums/{id}/reset
   ├─ Guard: album must be aborted
   ├─ Ownership guard
   ├─ CAS: aborted → idle
   ├─ Clean watermark snapshot if exists
   ├─ Delete price transient
   └─ Return success
```

---

## Recovery Scan (runs every 5 min)

```
Recovery_Scheduler::scan_and_recover()
│
├─ 1. Find stale uploading albums (heartbeat >5 min stale)
│   └─ CAS: uploading → aborted
│
├─ 2. Find stale deleting albums (>1h)
│   └─ CAS: deleting → aborted
│
├─ 3. Cleanup_Scheduler::recover_stuck_watermarking_photos()
│   ├─ Find photos stuck in watermarking >1h
│   │   └─ CAS: watermarking → failed
│   │   └─ Re-enqueue
│   ├─ Find photos in uploaded on watermarking albums (no time limit)
│   │   └─ Re-enqueue (without is_album_job_pending check)
│   └─ Return count
│
├─ 4. Cleanup_Scheduler::retrigger_failed_photos()
│   ├─ Find photos in failed on watermarking albums
│   ├─ Skip if retry_count >= PHOTO_RETRY_LIMIT (5)
│   ├─ CAS: failed → uploaded
│   └─ Re-enqueue
│
├─ 5. Cleanup_Scheduler::recover_uploaded_on_aborted()
│   ├─ Find uploaded photos on aborted albums
│   └─ Re-enqueue watermark
│
├─ 6. Cleanup_Scheduler::auto_settle_albums()
│   └─ Find watermarking/aborted albums with zero non-terminal photos
│   └─ CAS: watermarking → idle
│
└─ 7. Cleanup_Scheduler::delete_stale_failed_photos()
    └─ Delete failed photos >7 days (WC product + DB row)
```

---

## Daily Cleanup (runs hourly)

```
Cleanup_Scheduler::run_daily_cleanup()
│
├─ I. Expired photos
│   ├─ SELECT photos WHERE expiration_date < NOW() AND published=1
│   ├─ FOR each:
│   │   ├─ trash WC product
│   │   ├─ trash attachment
│   │   ├─ UPDATE photo SET published=0
│   │   └─ Logger::info()
│   └─ Return count
│
├─ II. Idempotency transient cleanup
│   └─ DELETE expired _transient_photolab_idempotent_* rows
│
└─ III. Orphan file scan
    └─ Find files in photos/<Album>/ without DB row → delete
```

---

## Heartbeat Flow

```
Browser                          Server
──────                          ──────

Every 30s:
POST /upload/heartbeat {job_id}
───────→  UPDATE albums SET last_heartbeat=NOW() WHERE id=job_id
          ├─ Affected rows = 1 → OK
          └─ Affected rows = 0 → Check album status
               ├─ aborted → return {aborted: true}
               └─ other → return {ok: true}
```

---

## Download Guard

```
Customer clicks download link (WooCommerce)
│
├─ Download_Guard hooks into woocommerce_download_product
├─ Lookup photo by wc_product_id
├─ IF photo NOT found → ALLOW (default)
├─ IF photo found AND photo_status = 'watermarked' → ALLOW
├─ IF photo found AND photo_status ≠ 'watermarked' → BLOCK (403)
│
└─ Protected original files are in photos/<Album>/ (.htaccess deny all)
```

---

## Ownership Verification

Every REST endpoint that modifies an album verifies ownership:

```
GET album row from DB
IF album.user_id IS NOT NULL AND album.user_id ≠ current_user_id
   → RETURN 403 "You do not have permission"
ELSE (user_id matches OR legacy row with NULL user_id)
   → PROCEED
```

---

## Race Condition Prevention

| RC | Scenario | Protection |
|----|----------|------------|
| RC-1 | Double /upload/start same album | CAS idle→uploading, duplicate check |
| RC-2 | Watermark changed during chunk | Snapshot at /start time |
| RC-3 | Same file uploaded twice | SHA256 + UNIQUE(file_hash, album_name) |
| RC-4 | Delete during upload | DELETE checks idle/aborted via CAS |
| RC-5 | Duplicate WC term from parallel chunks | Term created only in /start |
| RC-6 | Cleanup + DELETE same photo | Cleanup filters by album.status IN ('idle','aborted') |
