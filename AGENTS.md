# Photolab for WooCommerce — Agent Instructions

Plugin WordPress + WooCommerce per gestione e vendita massiva di album fotografici.
Upload, watermark inline e creazione prodotti WC in singola pipeline.

**Repository**: https://github.com/todotge/photolab-for-woocommerce
**Slug WP.org**: `photolab` (Text Domain: `photolab`)
**Versione**: 0.0.5
**Branch principale**: `main`

---

## Regole

- Mai inventare funzionalità, duplicare codice esistente o lasciare metodi incompleti.
- Mai modificare logica esistente senza richiesta esplicita.
- Mai rinominare funzioni/metodi/classi.
- Namespace: `Photolab`. PHPDoc completo. `$wpdb->prepare()` sempre.
- Tailwind: build locale (`npm run build:css`), mai CDN.
- Testo utente: sempre internazionalizzato (`__()`, `esc_html__()`), text domain `'photolab'`.
- Output: sempre escapato (`esc_html()`, `esc_attr()`, `esc_url()`).
- Input: sempre sanitizzato (`sanitize_text_field()`, `sanitize_key()`, `absint()`).
- File operations: `wp_delete_file()` per file, `wp_mkdir_p()` per directory.

---

## Struttura plugin

```
photolab/
├── photolab.php                    # Bootstrap, costanti, autoload, hooks
├── includes/
│   ├── class-activator.php         # Attivazione, requisiti, DB schema, .htaccess
│   ├── class-admin.php             # Menu admin, enqueue asset, SPA shell
│   ├── class-admin-notices.php     # Notifiche admin dismissibili persistenti
│   ├── class-cleanup-scheduler.php # Pulizia foto scadute, daily failsafe
│   ├── class-database.php          # Schema DB, migrazioni idempotenti
│   ├── class-download-guard.php    # Blocco download non-watermarked (HTTP 425/410)
│   ├── class-lock.php              # Lock distribuito (MySQL GET_LOCK / transient)
│   ├── class-logger.php            # Logging strutturato, contesto, redazione
│   ├── class-recovery-scheduler.php# Abort upload stantii, self-healing
│   ├── class-state-machine.php     # FSM con CAS, transizioni atomiche
│   ├── class-watermark-job.php     # Generazione thumbnail (woocommerce_thumbnail)
│   ├── class-watermark-processor.php# Compositing watermark (Imagick + GD)
│   └── rest/
│       ├── class-album-controller.php     # CRUD album + reset
│       ├── class-heartbeat-controller.php # Heartbeat client
│       ├── class-photo-controller.php     # Stato watermark
│       ├── class-settings-controller.php  # Configurazione globale
│       ├── class-upload-controller.php    # Pipeline upload (start/chunk/complete)
│       └── class-watermark-controller.php # Upload/delete watermark
├── assets/
│   ├── css/admin.css               # Tailwind compilato (build locale)
│   ├── css/admin-input.css         # Sorgente Tailwind
│   ├── js/admin.js                 # SPA vanilla JS
│   ├── icon.svg                    # Icona menu admin (base64 in PHP)
│   └── logo.svg                    # Logo brand nell'header admin
├── templates/
│   └── admin-page.php              # Shell SPA HTML
├── languages/
│   └── photolab.pot                # Template traduzioni
├── tests/
│   ├── Unit/ (19 file)             # Test senza WP/WC
│   └── Integration/ (11 file)      # Test con MySQL + WP + WC
├── bin/                            # Script setup test
├── stubs/                          # Stub PHPStan per costanti plugin
├── screenshots/                    # Screenshot per WP.org
├── README.md                       # Readme community GitHub
├── readme.txt                      # Readme WP.org
├── CHANGELOG.md
└── LICENSE                         # GPLv2
```

---

## Comandi plugin

```bash
# Sviluppo
cd /media/luke/TODOT/pubblication/photolab
npm run build:css              # Compila Tailwind
npm run watch:css              # Watch Tailwind

# Test (usa composer.phar dal runner o composer globale)
php composer.phar run test                           # Tutti i test
php composer.phar run test -- --testsuite unit       # Solo unit (no WP/WC)
php composer.phar run test -- --testsuite integration # Solo integration (richiede MySQL)
php composer.phar run test -- --filter TestMetodo    # Singolo metodo

# Qualità
php composer.phar run phpcs    # PHPCS WordPress standard (--warning-severity=0)
php composer.phar run phpstan  # PHPStan level 6
php composer.phar run phpstan:baseline  # Rigenera baseline

# ZIP (esclude test, vendor, tooling, MD non-plugin)
rm -f ../photolab.zip && zip -r ../photolab.zip . \
  -x ".git/*" ".github/*" "node_modules/*" "tests/*" "vendor/*" \
  "composer.*" "package*" "tailwind.config.js" "phpstan*" "phpunit*" \
  "docs/*" "bin/*" ".env.testing" ".gitignore" ".looper/*" "stubs/*" \
  "*.md~" ".gitignore~" "ARCHITECTURE.md" "FLOWS.md" "INSTRUCTIONS.md" \
  "CODE_OF_CONDUCT.md" "CONTRIBUTING.md" "SECURITY.md"
```

---

## CI Gates (verificati su ogni push)

| Gate | Comando | Fallimento blocca merge |
|------|---------|------------------------|
| PHPCS | `composer run phpcs` | Sì |
| PHPStan | `composer run phpstan` | Sì |
| Unit test | `composer run test -- --testsuite unit` | Sì |
| Integration test | Solo su PR non-draft | No (self-hosted runner) |
| Tailwind build | `npm run build:css` + verifica output non vuoto | Sì |
| Security settings | Dependabot alerts + auto-fix | No |

---

## WP.org compliance (Plugin Check)

Il plugin ha passato il Plugin Check scan. Warning rimanenti sono tutti falsi positivi:
- `UnescapedDBParameter`: `$wpdb->prefix . 'Photolab_*'` è pattern sicuro, Plugin Check non lo riconosce.
- `DirectDB.NoCaching`: atteso per plugin con tabelle custom.
- `SchemaChange`: atteso per migrazioni DB.

**Regole per nuovo codice**:
- Ogni stringa utente usa text domain `'photolab'` (non `'photolab-for-woocommerce'`).
- File operations: `wp_delete_file()` per cancellare file, ignora `rmdir()` per directory uploads.
- Nessun `proc_open()`, `exec()`, `move_uploaded_file()` senza phpcs:ignore motivato.
- `set_time_limit()` già wrappato in `function_exists()`.

---

## Skills da utilizzare

| Task | Skill |
|------|-------|
| Sviluppo backend WC | `woocommerce-backend-dev` |
| Sviluppo plugin WP | `wp-plugin-development` |
| REST API | `wp-rest-api` |
| Debug | `systematic-debugging` |
| TDD | `test-driven-development` |
| Code review WC | `woocommerce-code-review` |
| Performance WC | `woocommerce-performance` |
| Standard WP | `wordpress-documentation` |
| API WC | `woocommerce-docs` |

---

## Looper

Il daemon Looper reviewa le PR automaticamente.

- **Bot account**: `photosync-team` — review e fix da questo utente
- **Auto-discovery**: richiedere review a `photosync-team` → Looper avvia reviewer
- **gh**: `/media/luke/TODOT/Applicazioni/looper/gh` — autenticato come `photosync-team`
- **Working directory**: `/media/luke/TODOT/pubblication/photolab`

---

## Nota per estensioni

Il plugin è progettato come base. Estensioni future (`multidomain`, `pro`) devono:
- Rispettare gli stessi standard WP.org
- Usare gli hook/filter esistenti (non modificare file core)
- Mantenere compatibilità HPOS
- Documentare con PHPDoc completo
