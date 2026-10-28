# Project BAYANI database foundation

The application remains a browser-local prototype. This phase adds the Laravel schema and Eloquent model foundation; it does not connect the prototype UI to MySQL or import browser data.

## Local XAMPP setup

1. Start MySQL in XAMPP and confirm the port shown in its control panel. The conventional port is `3306`, but use the port configured on this machine.
2. Create an empty database named `project_bayani` in phpMyAdmin (or with an authorized MySQL account). Use `utf8mb4` / `utf8mb4_unicode_ci` when choosing a collation.
3. Back up the existing `.env`, then set its local database entries to the values verified for this XAMPP installation. Typical defaults are shown below; they are examples, not credentials verified by this project:

   ```dotenv
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=project_bayani
   DB_USERNAME=root
   DB_PASSWORD=
   ```

4. Clear cached Laravel configuration and verify the selected connection/database before writing schema:

   ```powershell
   php artisan config:clear
   php artisan db:show --database=mysql
   php artisan migrate:status --database=mysql
   ```

5. After confirming that `db:show` connects to the intended empty or expected database, apply the additive migration:

   ```powershell
   php artisan migrate --database=mysql
   php artisan migrate:status --database=mysql
   ```

`migrate:fresh` and `db:wipe` are intentionally not part of setup. Never copy real credentials into source control; `.env` is machine-local. Configuration in `config/database.php` already supports MySQL and uses Laravel's `DB_*` environment variables.

## Tables and model relationships

- `users` and `password_reset_tokens` provide the standard account foundation; users have many contributed materials, reviews, activity entries, and favorited materials.
- `categories` is the material reference table; education catalogs provide curriculum, subject, grade, type, track, and strand metadata.
- `materials` belongs to a contributor and optional category; it has soft deletes and many reviews and favorites.
- `material_reviews` belongs to its material and reviewer. Physical material deletion cascades to review rows; contributor and reviewer deletion is restricted while their records remain.
- `favorites` is a user/material pivot with a unique pair constraint and cascading cleanup.
- `notifications` uses Laravel's UUID and polymorphic database-notification format.
- `activity_logs` belongs optionally to a user and has an optional polymorphic subject. User deletion keeps the audit entry and nulls its user reference.
- `system_settings` has a unique key and a text value plus a type hint.

Material status values preserve prototype spelling: `Draft`, `Pending`, `Approved`, `Rejected`, and `Revision Requested`. Allowed transitions are Draft → Pending; Pending → Approved, Rejected, or Revision Requested; Rejected/Revision Requested → Pending; Approved is terminal. `Material::canTransitionTo()` describes the rule; no review endpoint or authorization is implemented yet.

## Test isolation and Phase 4 handoff

PHPUnit forces SQLite `:memory:` for test runs. `RefreshDatabase` therefore never operates on the developer's `.env` database. The database tests cover migration tables, relationships, status rules, unique favorites, and foreign-key enforcement.

The prototype's `bayani.demo.v1` localStorage object stores materials, taxonomy arrays, role-keyed demo profiles, user records, preferences, notifications, activity, favorites, recently viewed IDs, download history, and feedback. `bayani.role` only switches the demo view; it is not an authenticated user. Temporary files are in tab-scoped sessionStorage. No automatic export/import is possible or performed here.

Phase 4 should first define real authentication and roles, then add policies/request validation and transactional material/review workflows, durable private file storage, API or controller integration, and an explicit user-controlled data export/import path if browser records need to move. Decide how event histories and browser-only file bytes are treated before importing data.
