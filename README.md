# Project BAYANI - Database-backed repository and CMS

Laravel 12, Blade, Bootstrap 5 and vanilla JavaScript. Laravel sessions, accounts, educational resource metadata, submissions, review decisions, and normalized curriculum/subject/grade/material-type catalogs use MariaDB. Existing browser storage is preserved and is not mixed into the real repository.

## Run locally

Requires PHP 8.2+, Composer, Node.js 24.15+ (tested with 24.16) and npm.

1. Install dependencies with composer install and npm install.
2. Copy .env.example to .env only if an environment file does not already exist, set the verified local MySQL values, then run php artisan key:generate only if APP_KEY is empty.
3. Run php artisan migrate after confirming the selected database, then run php artisan serve and npm run dev in separate terminals (or npm run build for built assets).
4. Visit http://127.0.0.1:8000. Public registration creates contributor accounts. To set up the project owner as Administrator: (1) register a Contributor account with the owner's email, (2) from an authorized server shell run `php artisan bayani:grant-admin owner@example.org` and confirm the prompt, (3) sign in with that account's existing password, and (4) open `/admin`. Replace `owner@example.org` with the registered address. The command changes only the existing account's role; it does not set or reset passwords. Never promote an account through public registration.

Profile pictures accept PNG, JPG/JPEG, and WEBP up to 10 MB. PHP must allow at least `upload_max_filesize = 10M` and `post_max_size = 12M` (the multipart request includes form fields). For the local XAMPP PHP runtime, find the active `php.ini` with `php --ini` (Apache may use a separate `php.ini`), set both values, then restart Apache or the PHP server. The current CLI PHP reports 40M for both settings. Uploaded images are decoded, resized to a maximum 1024-pixel dimension, re-encoded, and stored under the configured public disk's `profile-images` directory; arbitrary SVG and other formats are rejected. Existing profile images are left in place when a new image is uploaded.

## Empty-state walkthrough

- Register a Contributor account to access the authenticated contributor workspace.
- An authorized operator can promote an existing account with the confirmed `bayani:grant-admin` command; administrator routes reject contributors.
- Administrators manage curricula, subjects, grade levels, curriculum-grade-subject mappings, tracks, strands/specializations, and material types under Education Catalog. Map offerings from official curriculum guides; inactive records remain available for historical material.
- Contributor resources support multiple subjects and grades, optional curriculum and type, topic/keywords, applicable SHS track/strand, and optional category. Administrators review submissions; only approved materials appear publicly. Repository filters, sorting, and pagination query MariaDB.
- Contributors can upload PDF, DOCX, PPTX, XLSX, JPG/JPEG, PNG, and WEBP files to private Laravel storage. Approved files can be previewed/downloaded through controlled routes; drafts and submissions are owner/admin-only.

- Phase 6 verifies source-backed subject mappings and adds persistent, permission-checked material file uploads. See [docs/education-catalog.md](docs/education-catalog.md) for DepEd sources and unmapped grade scopes, and [docs/file-storage.md](docs/file-storage.md) for allowed files, access, retention, and backup requirements.
- Contributors can save drafts, submit, view feedback, correct requested/rejected submissions, and resubmit. Administrators review Pending submissions, record decisions and remarks, and publish approved materials. Review history, activity audit rows, and database notifications use existing tables. See [docs/review-workflow.md](docs/review-workflow.md) for transition and access rules.

New storage starts empty. Older recognized sample records are hidden without deleting the stored data. Administrator > Legacy data cleanup offers an explicit confirmed cleanup. User-created records and taxonomy values are preserved. There is no automatic reset or sample reseeding.

Authenticated profile details and avatars are saved to the database. Favorites, materials, search results, publication details, submissions, review, catalogs, and reports use MariaDB. Appearance preferences and unrelated legacy prototype tools may still use browser storage; browser-stored records are never automatically imported or deleted and are not repository resources.

## File limitations

Material attachments use the private `bayani_uploads` disk under `storage/app/private/materials`. The default upload limit is 20 MB and can be changed with `BAYANI_MAX_UPLOAD_MB`; PHP upload and post limits must also permit that size. PDF, image, XLSX, and DOCX previews use authorization-checked routes; PPTX is clearly download-only. Repository thumbnails use actual attachment content and existing records can be backfilled with `php artisan bayani:backfill-thumbnails`. See [docs/file-storage.md](docs/file-storage.md) for preview behavior, validation, retention, access rules, and backup details. The old browser prototype may still contain temporary file bytes; those remain untouched and are not used by the database repository.

Failed writes roll back in-memory changes. Unreadable saved data is preserved and saving is blocked. Stale tabs cannot overwrite newer records; reload a stale tab before saving again. Do not clear browser storage to troubleshoot without backing up your records.

## Checks

- php artisan route:list --except-vendor
- php artisan test
- npm test (or node --test tests/js/*.test.js)
- node --check resources/js/bayani-phase12.js
- npm run build
- php artisan view:cache

On this Windows machine, the npm PowerShell launcher is blocked and the npm.cmd path is broken. The installed CLI works with: node "C:/Program Files/nodejs/node_modules/npm/bin/npm-cli.js" run build.

See [PROGRESS.md](PROGRESS.md) for MySQL migration, catalog, test, and remaining-work results. See [docs/education-catalog.md](docs/education-catalog.md) for verified curriculum mappings and official sources, [docs/file-storage.md](docs/file-storage.md) for secure attachment handling, and [docs/authentication.md](docs/authentication.md) for role and route security details. PHASE_1_4_AUDIT.md and PHASE_1_3_AUDIT.md retain earlier continuation history.

See [docs/phase-5.md](docs/phase-5.md) for the database integration boundary, remaining browser-local features, and a safe optional prototype-data import plan.

See [docs/database-setup.md](docs/database-setup.md) for XAMPP setup, schema relationships, localStorage mapping, and Phase 5 boundaries. This development machine's `.env` is configured for its verified local XAMPP database; keep `.env` private and configure each installation with its own credentials.

Phase 8.3's MariaDB catalog snapshot, DepEd source register, and remaining verification gaps are in [docs/deped-catalog-sources.md](docs/deped-catalog-sources.md). The baseline Categories are Project BAYANI organizational groupings, not official DepEd categories. The `ReferenceDataSeeder` is repeatable. Phase 8.3A permanently removed Barangay and Historical Era metadata from the live schema and application. Phase 8.3B adds read-only PDF, image, XLSX, and DOCX previews plus private content-derived thumbnails; current implementation and browser verification gaps are recorded in [PROGRESS.md](PROGRESS.md).

Phase 1.4 adds actual image and PDF-first-page thumbnails using locally bundled PDF.js, clearly labelled document fallbacks, redesigned reusable cards and an upload drop area with selected-file previews. PDF covers are cached in memory; no files are sent to a preview service. Share/copy links identify a local record and cannot make its browser-only data available on other devices.

## Source map

- routes/web.php: prototype routes.
- resources/views: shared Blade layout and navigation.
- resources/js/bayani-data.js: persistence, migration and workflows.
- resources/js/bayani-phase12.js: current UI implementation (filename retained to preserve existing imports).
- resources/css/phase11.css: shared styling and Phase 1.3 overrides.
- resources/css/phase14.css: current visual system and responsive card/upload styling.
- resources/js/bayani-files.js, bayani-pdf.js and bayani-upload.js: temporary file covers and upload previews.
- tests/js/store.test.js: data integrity and workflow regression tests.

## Phase 10 handoff: local presentation

Project BAYANI is a Laravel 12 educational-resource repository and CMS using Blade, MariaDB, Bootstrap 5, vanilla JavaScript, and Vite. Contributors manage and submit learning materials; administrators manage catalogs and review submissions; guests browse approved materials. Attachments remain in private Laravel storage and are served through authorized routes. DepEd-related catalog content is not officially certified.

### Install and run locally

1. Install PHP 8.2+, Composer, Node.js 24.15+ and npm. Required PHP extensions include `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `gd`, `zip`, and `xml` (the current XAMPP PHP also has `curl`).
2. Run `composer install` and `npm install`.
3. For a new local setup only, copy `.env.example` to `.env`. It defaults to SQLite; for MariaDB set `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_PORT=3306`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` for the intended local database. Keep `.env` private.
4. Set `APP_ENV=local`, `APP_DEBUG=true`, and `APP_URL` for local use. Run `php artisan key:generate` only when the selected `.env` has no key. For production, use `APP_ENV=production`, `APP_DEBUG=false`, and a unique securely managed `APP_KEY`; production deployment/security have not been verified here.
5. On a new or intentionally unmigrated database, verify the selected database before `php artisan migrate`. Never use `migrate:fresh` on a database with user data. This MariaDB has 13 applied migrations.
6. Run `php artisan serve` and `npm run dev` in separate terminals. Run `npm run build` for production assets.

New public registrations receive the Contributor role. Administrators have additional review/catalog permissions and are assigned separately with the confirmed server-side `php artisan bayani:grant-admin account@example.org` command. Never grant the role through public registration. See [docs/authentication.md](docs/authentication.md) and [docs/review-workflow.md](docs/review-workflow.md).

### Upload and storage readiness

The application limit is 20 MB per material file (`BAYANI_MAX_UPLOAD_MB`). The inspected CLI PHP is 8.2.12 with `upload_max_filesize=40M`, `post_max_size=40M`, and `memory_limit=512M`. The private `bayani_uploads` disk is under `storage/app/private`; ensure the application account can write there and keep it outside the web root. Profile images have a 10 MB application limit and use the configured public disk. PHP and Apache can load different `php.ini` files; check with `php --ini`. Do not create a public storage link for private materials.

### Backups

Create a unique destination outside `public` and the project web root. This Windows PowerShell example prompts for the database password instead of putting it in shell history, then archives private uploads:

```powershell
$backup = Join-Path (Split-Path (Get-Location).Path -Parent) ("project_bayani_backups\" + (Get-Date -Format "yyyyMMdd-HHmmss"))
if (Test-Path -LiteralPath $backup) { throw "Backup folder already exists; choose a new timestamp." }
New-Item -ItemType Directory -Path $backup | Out-Null
& C:\xampp\mysql\bin\mysqldump.exe --host=127.0.0.1 --port=3306 --user=YOUR_DB_USER --password --single-transaction --routines --triggers --events --hex-blob --databases YOUR_DB_NAME --result-file=(Join-Path $backup "project_bayani.sql")
if ($LASTEXITCODE -ne 0) { throw "Database backup failed." }
Compress-Archive -Path .\storage\app\private\materials -DestinationPath (Join-Path $backup "private-materials.zip")
```

Replace the database username/name placeholders. Verify both files are nonempty and inspect archive contents before considering the backup complete. Backups contain user/material data and must remain private. Do not restore over the live database for routine verification. See [docs/file-storage.md](docs/file-storage.md).

### Known limitations

- Theme and notification preferences are browser-local and do not sync between devices. Profile details, resources, favorites, and review workflows are account/database-backed.
- PPTX is download-only; browser document previews may differ from Office rendering.
- Catalog mappings have not been officially certified by DepEd.
- Vite currently warns about large PDF/ExcelJS chunks, although the build succeeds.
- Owner-confirmed working features and passing local checks make this ready for local presentation, not production-ready.