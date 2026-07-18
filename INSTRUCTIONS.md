# Photolab — Setup & Usage Instructions

---

## Requirements

| Requirement | Minimum |
|-------------|---------|
| PHP | 8.1+ |
| WordPress | 6.5+ |
| WooCommerce | 8.0+ |
| MySQL | 8.0+ / MariaDB 10.6+ |
| Imagick extension | preferred (GD fallback) |
| memory_limit | ≥ 256MB |
| max_execution_time | ≥ 120s |
| upload_max_filesize | ≥ 20MB |
| post_max_size | ≥ 100MB |

---

## Installation

### From ZIP
1. Download `photolab.zip`
2. WordPress Admin → **Plugins → Add New → Upload Plugin**
3. Select ZIP, click Install, then **Activate**

### Requirements Check
On activation, Photolab checks:
- PHP ≥ 8.1
- WordPress ≥ 6.5
- WooCommerce ≥ 8.0
- Imagick or GD extension loaded
- `memory_limit`, `max_execution_time`, `upload_max_filesize`, `post_max_size`

If any fail, the plugin shows a notice and refuses to activate.

---

## Admin Panel

After activation, a **Photolab** menu item appears in the WordPress sidebar.

### Upload Photo Section

1. **Album Name** — unique name for the album (becomes WC product category)
2. **Album Expiration** — optional expiry date (all photos unpublished after date)
3. **Price** — unit price for all photos in this album
4. **Select Photos** — choose multiple image files (JPEG, PNG, WebP, GIF)
5. **Watermark** — configure watermark (optional)
6. **Upload** — starts the chunked upload pipeline

**During upload:**
- Progress bar shows current chunk progress
- Each chunk processes 5 files at a time
- Files are uploaded, watermarked, and published as WC products immediately
- The page shows "Upload completed: X photo(s) saved" when done

### Galleries Status Section

Lists all albums with:
- Album name
- Photo count
- Expiration date
- Status badge (Idle, Uploading, Processing, Aborted)
- Action buttons: Reset (for aborted), Delete (for idle/aborted)

### Watermark Modal

- **Upload PNG**: select a PNG file to use as watermark
- **Position**: Full width (top-left stretched) or Bottom right (30% size)
- **Save**: uploads file + saves position
- **Delete**: removes watermark
- **Update position**: changes position without re-upload

---

## Watermark Settings

The watermark is a PNG file composited onto every uploaded photo.

### Position Options

| Option | Behavior |
|--------|----------|
| `fullwidth` | Stretched to full photo width, height proportional, composited at top-left |
| `bottom_right` | 30% of photo width, 2% padding from corner, composited at bottom-right |

### File Location
- Active watermark: `wp-content/uploads/Photolab/assets/watermark.png`
- Snapshots: `assets/watermark_<timestamp>.png` (created during batch upload)

### No Watermark
If no watermark PNG is uploaded, photos are still resized to 1200px and saved (no compositing). The `Watermark_Processor::apply()` checks `file_exists(watermark_path)` and skips compositing if missing.

---

## Album Expiration

Set an expiration date when creating an album. Effects:
- **No visible change** until `Cleanup_Scheduler::run_daily_cleanup()` runs
- Cleanup removes WC products and attachments for expired photos
- Photos remain in DB with `published = 0`
- Watermarked files remain on disk (not deleted)

---

## Secure Purchase Flow

1. Customer browses shop → sees product with watermarked thumbnail
2. Customer purchases → receives download link (WooCommerce my-account)
3. Download link points to **original full-resolution file** in `photos/<Album>/`
4. `Download_Guard` verifies photo is watermarked before allowing download
5. Original file is in protected directory (`.htaccess Deny from all`)

If a product is not yet watermarked (e.g., stuck in `uploaded`), `Download_Guard` returns 403.

---

## WooCommerce Integration

### Products Created
- Type: Simple, Virtual, Downloadable
- Status: Published (immediately)
- Category: Album name (product_cat)
- Featured image: Watermarked file (Media Library)
- Download: Original file URL
- Price: Unit price from the upload form

### Image Size
Only `woocommerce_thumbnail` (300×300 cropped) is generated.  
Other WooCommerce image sizes (`woocommerce_single`, `woocommerce_gallery_thumbnail`) fall back to the full watermarked file.

---

## PHP Development

### Build CSS
```bash
cd photolab/
npm install
npm run build:css
```

### Run Tests
```bash
cd photolab/
composer install
composer run test         # PHPUnit
composer run phpcs        # PHPCS (WordPress standards)
composer run phpcbf       # Auto-fix PHPCS issues
```

### Generate .pot
```bash
wp i18n make-pot photolab/ photolab/languages/photolab.pot --domain=photolab
```

---

## Troubleshooting

### "Unable to enqueue watermark job"
**Cause**: Old code without inline watermark. WC()->queue() returning 0.  
**Fix**: Photolab uses inline watermark processing.

### "Album in stato idle, chunk rifiutato"
**Cause**: Album settled to idle before all chunks were processed (old async flow).  
**Fix**: Photolab uses inline watermark — not applicable with async recovery.

### "Undefined constant 'WP_Error'"
**Cause**: Corrupted `class-watermark-processor.php`.  
**Fix**: Reinstall the plugin from a fresh download.

### "You do not have permission to access this album"
**Cause**: Album created by a different user (user_id mismatch).  
**Fix**: Update `user_id` in DB or delete the album as the original user.

### Photos not watermarked
**Cause**: The recovery cron may not have processed them yet, or the watermark failed.  
**Check**: Album status should be `idle` or `watermarking`. Check `photo_status` in DB.

### Progress bar not showing
**Cause**: JavaScript error or old browser.  
**Check**: Browser console (F12) for errors. Photolab requires a modern browser with `fetch()` support.

---

## Nginx Configuration

Add these rules to protect original photos:

```nginx
location ^~ /wp-content/uploads/Photolab/photos/ {
    deny all;
    return 403;
}
```

---

## Architecture Notes

- **No Interactivity API**: Vanilla JS SPA with `fetch()` + `wp_localize_script`
- **No CDN Tailwind**: Local build via `assets/css/admin.css`
- **Inline watermark**: Applied during upload chunk, not async
- **CAS for all transitions**: Race condition prevention via atomic UPDATE
- **FSM**: State machine prevents invalid transitions
- **Self-healing**: Recovery cron aborts stuck uploads, re-enqueues failed watermarks
