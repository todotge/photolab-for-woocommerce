=== Photolab ===
Contributors: photolabdev
Tags: woocommerce, photos, gallery, watermark, digital downloads
Requires at least: 6.5
Tested up to: 6.7
Stable tag: 0.0.5
Requires PHP: 8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Gestione e vendita massiva di album fotografici su WooCommerce.

== Description ==

Photolab è un plugin per la gestione e vendita massiva di album fotografici su WooCommerce. Consente di caricare migliaia di foto in batch, applicare watermark in background e pubblicarle come prodotti virtuali scaricabili. La versione 2.0 introduce una macchina a stati finiti (FSM) per la gestione concorrente, recovery automatica degli upload interrotti, watermark asincrono via Action Scheduler, multi-upload paralleli con rate limiting e protezione del download durante la fase di processing.

**Caratteristiche principali:**

* Upload massivo di foto in lotti (supporta 2000+ foto per album)
* Watermark asincrono applicato in background da Action Scheduler (non blocca l'upload)
* Posizione watermark configurabile: full-width o bottom-right
* Ogni foto diventa un prodotto WooCommerce virtuale e scaricabile
* Le foto originali sono protette dall'accesso pubblico; i clienti scaricano solo dopo l'acquisto
* Download bloccato finché la foto non è stata processata (HTTP 425 in fase di watermarking, 410 se scaduta)
* Multi-upload parallelo: fino a 3 album in upload contemporaneamente per utente
* Recovery automatica degli upload interrotti (heartbeat client + cron server-side, FSM con stato `aborted` e reset manuale)
* Idempotency-Key sugli endpoint chunk per retry sicuri dopo timeout di rete
* Distributed lock opzionale (Redis o transient WP) per ambienti multi-server
* Cleanup giornaliero failsafe: re-trigger foto stuck, retry budget per foto, retention log e Action Scheduler
* Logging strutturato con auto-contesto (user_id, album_id, photo_id) e redazione dati sensibili
* Gestione album con data di scadenza automatica
* Deduplicazione SHA-256 per evitare foto duplicate nello stesso album
* Pannello admin SPA senza ricaricamenti di pagina, comunicazione via REST API
* Supporto Imagick (raccomandato) e GD come fallback per l'elaborazione immagini
* Compatibile con WooCommerce HPOS

**Requisiti:**

* WordPress 6.5+
* WooCommerce 8.0+
* PHP 8.1+
* Estensione PHP Imagick (consigliata) o GD
* memory_limit >= 256MB
* max_execution_time >= 60s
* upload_max_filesize >= 20MB
* post_max_size >= 100MB

== Installation ==

1. Carica la cartella `photolab` nella directory `/wp-content/plugins/`.
2. Attiva il plugin dalla schermata "Plugin" di WordPress.
3. Assicurati che WooCommerce sia installato e attivo prima di attivare Photolab.
4. Verifica che i Pretty Permalinks siano abilitati in Impostazioni → Permalink (qualsiasi struttura eccetto "Normale").

**Protezione delle foto originali:**

La cartella `wp-content/uploads/Photolab/photos/` è protetta tramite un file `.htaccess` che nega l'accesso diretto a tutti i file. Questo meccanismo funziona su server **Apache** con `mod_rewrite` abilitato.

Se utilizzi **Nginx**, il file `.htaccess` non viene letto dal server. Devi aggiungere manualmente una regola nel blocco `server` per bloccare l'accesso a quella directory. Esempio:

```
location ~* /wp-content/uploads/Photolab/photos/ {
    deny all;
    return 403;
}
```

Consulta la documentazione del tuo hosting provider per applicare questa configurazione.

== Frequently Asked Questions ==

= Quante foto posso caricare per album? =

Il plugin è progettato per gestire volumi elevati, fino a 2000+ foto per album. L'upload avviene in chunk da 5 file per volta (filtrabile via `photolab_chunk_size`) per garantire la compatibilità con hosting con limiti di esecuzione ridotti.

= Posso usare il plugin senza Imagick? =

Sì. Se Imagick non è disponibile, Photolab utilizza automaticamente la libreria GD come fallback per l'applicazione del watermark. Le performance su volumi elevati saranno ridotte rispetto a Imagick.

= Il watermark viene applicato alle foto originali? =

No. Il watermark viene applicato a una copia della foto (salvata in `wp-content/uploads/Photolab/watermarked/`). La foto originale è conservata separatamente e protetta dall'accesso pubblico.

= Quando viene applicato il watermark? =

Dalla v2.0.0 il watermark è asincrono: viene applicato in background da un job Action Scheduler dopo che la foto è stata caricata. L'endpoint `/upload/chunk` ritorna in pochi secondi senza dover aspettare il compositing. Il pannello admin mostra il progresso in tempo reale via polling su `GET /photolab/v1/photos/watermark-status?album_id=X`. Finché una foto non è in stato `watermarked` il download è bloccato (HTTP 425).

= Cosa succede se chiudo il browser durante l'upload? =

Il client invia un heartbeat ogni 30 secondi. Se il server non riceve heartbeat per 5 minuti (oppure 10 minuti se l'heartbeat non è mai arrivato), un job di recovery passa l'album in stato `aborted`. Da pannello admin puoi resettare l'album (`POST /albums/{id}/reset`) per riprendere l'upload, oppure eliminarlo. Le foto già caricate rimangono come prodotti WooCommerce validi.

= Posso caricare più album contemporaneamente? =

Sì, fino a 3 album in upload paralleli per utente. Tentativi oltre questo limite ritornano HTTP 429. Album con stesso nome già in volo per lo stesso utente ritornano HTTP 409.

= Cosa succede se il chunk di upload va in timeout di rete? =

Il client può ripetere lo stesso `/upload/chunk` con header `Idempotency-Key`. La risposta originale viene replicata dal transient (TTL 24h) senza riprocessare i file. Senza header, il comportamento è invariato.

= Cosa succede quando una foto scade? =

Se viene impostata una data di scadenza per l'album, il plugin elimina automaticamente il prodotto WooCommerce, l'immagine watermarked e il record dal database tramite un job giornaliero gestito da Action Scheduler (incluso in WooCommerce).

= Come funziona il cleanup giornaliero? =

Un job Action Scheduler `photolab_daily_cleanup` esegue ogni 24 ore (default 03:00 UTC) un sweep failsafe: rileva job watermark stuck >1h, rimette in coda foto bloccate in `watermarking`, riprova foto `failed` entro un budget di 5 retry per foto, purga transient idempotency scaduti, scansiona orfani disco/DB (solo log) ed elimina i log Photolab più vecchi di 30 giorni (filter `photolab_log_retention_days`). I log Action Scheduler vengono auto-eliminati dopo 7 giorni.

= Il plugin funziona in ambienti multi-server? =

Sì. La v2.1.0 introduce un distributed lock opzionale: se WordPress usa un object cache esterno (es. Redis), il lock sfrutta `wp_cache_add` (atomico SET NX). In assenza di object cache esterno, il fallback è un transient. Tentativi concorrenti su `/upload/chunk` per lo stesso album da nodi diversi ritornano HTTP 423. Il CAS sul DB resta come ultima difesa. Disabilitabile via filter `photolab_use_distributed_lock`.

= I Pretty Permalinks sono obbligatori? =

Sì. Le REST API di WordPress richiedono una struttura permalink diversa da "Normale". Il plugin mostra un avviso admin se i Pretty Permalinks non sono attivi.

= Il plugin funziona con WooCommerce HPOS? =

Sì. Photolab non accede direttamente alla tabella `wp_posts` per i prodotti WooCommerce ed è compatibile con la funzionalità High-Performance Order Storage (HPOS).

= Dove trovo i log? =

In `WooCommerce > Status > Logs`, filtrando per source `photolab*`. Source disponibili: `photolab`, `photolab-fsm`, `photolab-upload`, `photolab-heartbeat`, `photolab-recovery`, `photolab-watermark-job`, `photolab-rate-limit`, `photolab-ownership`, `photolab-download-guard`, `photolab-idempotency`, `photolab-cleanup`, `photolab-lock`, `photolab-logger`. Ogni voce include automaticamente `user_id`, `album_id` e altre chiavi contestuali. Dati sensibili (password, token, email) vengono redatti automaticamente.

== Changelog ==

= 2.1.0 =
* FASE 8 — Optional distributed lock (`Photolab\Lock`) for multi-server deployments. Backend detection: filter `photolab_use_distributed_lock` (default true) → external object cache (`wp_cache_add` atomic SET NX, group `photolab-locks`) → transient fallback (`photolab_lock_*` prefix).
* Lock integrated in `Upload_Controller::chunk()`: lock key `photolab_chunk_{album_id}`, timeout 60s, body extracted in `chunk_locked()` private method, release in `finally` to cover all return paths and exceptions.
* HTTP 423 Locked response (`album_locked` error code) when a concurrent worker on another node holds the lock.
* New log source `photolab-lock` (acquire/busy/release/release-fail).
* No new external dependencies — works identically in single-server installs without Redis.
* FASE 9 — Structured logging: `Logger::log()` is now the central method. `info/debug/warning/error/critical` are backward-compatible aliases.
* Auto-context: `Logger::set_context($key, $value)` and `Logger::clear_context()` push request-scoped fields (`user_id`, `album_id`, `is_async_job`, `is_cron`) merged automatically into every log call. Wired in every REST callback and Action Scheduler hook (try/finally to prevent context leak).
* Sensitive-key redaction (password, token, secret, api_key, email) via `Logger::sanitize_context()`. Filterable via `photolab_log_sensitive_keys`.
* Log retention via `Logger::cleanup_old_logs()` (default 30 days, minimum 7, filter `photolab_log_retention_days`). Called from the daily cleanup orchestrator as the last step.
* New log source `photolab-logger`.

= 2.0.0 =
* FASE 1 — DB schema bumped to `1.3.0`. New columns: `albums.user_id`, `albums.upload_started_at`, `albums.last_heartbeat`, `albums.aborted_at`, `photos.album_id`, `photos.photo_status`, `photos.retry_count`, `photos.updated_at` (with `ON UPDATE CURRENT_TIMESTAMP`). `albums.status` widened to `VARCHAR(20)` (no ENUM ALTER on shared host). New indexes: `albums(user_id)`, `albums(status, last_heartbeat)`, `photos(album_id)`, `photos(photo_status)`. Idempotent migrations (`migrate_1_2_0_fsm`, `migrate_1_3_0_photos_updated_at`) that backfill `album_id` and `photo_status` from legacy data.
* FASE 2 — Finite State Machine + Compare-And-Swap. New `Photolab\State_Machine` with whitelisted column updates. Atomic transitions for both album and photo states. Endpoints `/upload/start`, `/upload/chunk`, `/upload/complete`, `DELETE /albums/{id}` migrated to CAS — no more direct `$wpdb->update()` on `status`.
* FASE 2.5 — Recovery: `POST /upload/heartbeat` (client every 30s) + AS hook `photolab_recovery_scan` (every 15 min). Stale heartbeat (>5 min) or never-received heartbeat (>10 min) → CAS `uploading → aborted`. New endpoint `POST /albums/{id}/reset` (CAS `aborted → idle`). Admin UI: red "Aborted" badge + amber Reset button.
* FASE 3 — Asynchronous watermark via `WC_Queue` (Action Scheduler). `/upload/chunk` no longer applies the watermark inline — photos are inserted with `photo_status='uploaded'` and the `photolab_watermark_batch` AS job (group `photolab_album_{id}`) handles compositing in the background. Per-photo CAS `uploaded → watermarking → watermarked` (or `failed` on exception). Retry budget 5 (filter `photolab_watermark_max_retries`), admin email on exhaustion. New `Watermark_Processor` (Imagick + GD, shared) and `Watermark_Job` classes. Album CAS `watermarking → idle` runs once every photo reaches a terminal state. New endpoint `GET /photos/watermark-status?album_id=X` (transient cache 2s) for admin polling.
* FASE 4 — Multi-upload + rate limiting. Up to 3 concurrent uploads per user (HTTP 429 above). Album-name dedup per user while in flight (HTTP 409). Ownership check (`user_id`) on every album endpoint — legacy rows with `user_id=NULL` exempt. AS group isolation (`photolab_album_{id}`) prevents cross-album interference. Admin UI: status badges (`Uploading`/`Processing`/`Aborted`/`Deleting`), Delete button disabled for non-`idle/aborted`, photo-count tooltip on aborted albums. New log sources `photolab-rate-limit`, `photolab-ownership`.
* FASE 5 — Download guard + Idempotency-Key. New `Photolab\Download_Guard` registers `woocommerce_product_file_download_path` (priority 10): `watermarked` → allow, `uploaded`/`watermarking` → HTTP 425 "still being processed", `failed`/`deleted` → HTTP 410 "no longer available", non-Photolab products no-op, DB error fail-OPEN to avoid blocking legitimate downloads on infrastructure outages. `/upload/chunk` accepts optional `Idempotency-Key` header (regex `[A-Za-z0-9_-]{1,128}`); successful responses cached as transient `photolab_idempotent_*` (TTL 24h). Replay returns the cached payload without reprocessing the chunk. New log sources `photolab-download-guard`, `photolab-idempotency`.
* FASE 6 — Daily cleanup failsafe. New AS hook `photolab_daily_cleanup` (24h, first run `tomorrow 03:00:00 UTC`). Orchestrates: stuck AS watermark jobs detection (`status=in-progress` + `COALESCE(last_attempt_gmt, scheduled_date_gmt) < UTC-1h`, log + admin notify on `attempts >= 5`), CAS `watermarking → failed` for photos stuck >1h with re-enqueue, retry-budget-aware CAS `failed → uploaded` (max 5 retries via option `photolab_watermark_retry_{photo_id}`, mirror `retry_count` column), idempotency transient purge, orphan disk/DB scan (log only, never delete). AS retention 7 days via top-level filter `action_scheduler_retention_period`. Per-photo retry counter bumped from `Watermark_Job` (atomic with the failure path) and cleared on `watermarked`. Admin notifications guarded by option `photolab_watermark_notified_{photo_id}` to prevent spam. New log source `photolab-cleanup`.
* Activator now uses `esc_html__()` and `array_map('esc_html', $errors)` in `wp_die()`.
* All admin JS strings translated to English.
* `set_time_limit(120)` wrapped in `function_exists()` check (WP.org compliance).

= 1.0.9 =
* Replace Tailwind CDN with local build (assets/css/admin.css) — WP.org Guideline #8 compliance.
* Add Domain Path: /languages header to plugin file.

= 1.0.8 =
* Initial public release.
* Upload massivo chunked con watermark e creazione prodotto WooCommerce in pipeline unificata.
* Pannello admin SPA via REST API con Tailwind CSS.
* Gestione album: creazione, lista paginata, eliminazione con cleanup completo.
* Cleanup automatico foto scadute via Action Scheduler.
* Protezione race condition su upload simultanei (RC-1 — RC-6).
* Supporto Imagick con fallback GD.
* Deduplicazione SHA-256 per album.
