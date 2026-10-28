# Phase 4 authentication

## Roles and registration

- Public visitors browse the homepage, repository, search, material details, About, and Help without registering.
- Public registration creates an active `contributor` account only. The request's `role` and `status` fields are ignored; the User model does not expose those attributes to ordinary mass assignment.
- Administrator accounts are not seeded and cannot be created through public registration. To grant administrator access to an existing account, an authorized operator with shell/database access runs:

  ```powershell
  php artisan bayani:grant-admin existing-account@example.org
  ```

  The command requires an explicit interactive confirmation. Registration must happen first. Never use or share a default administrator password.

## Login and protected pages

- `/login`, `/register`, `POST /login`, `POST /register`, and `POST /logout` use Laravel's web session and CSRF middleware. Login attempts are limited to five per minute per email/IP pair. Passwords use Laravel's hashed cast.
- Contributor routes under `/contributor/*` require an authenticated `contributor` role. Administrator routes under `/admin/*` require `admin`. Profile, settings, notifications, favorites, recent items, and download history require authentication. Guests are redirected to `/login`; the other role receives HTTP 403.
- `/demo/login` now redirects to real login. Navbar demo role switching was removed; localStorage demo role values are left intact but cannot authorize any route.
- Account profile fields are read from and written to the authenticated database user. Profile images are stored on Laravel's public disk; `php artisan storage:link` creates the public link.
- Theme preference remains browser-local and route-global. Authenticated prototype workspace data uses `bayani.user.<id>.v1`; the existing shared `bayani.demo.v1` record is left unchanged. This prevents one signed-in account from inheriting another browser's legacy demo workspace records.

## Database changes and limits

The additive migration `2026_10_27_000002_add_profile_details_to_users_table` adds nullable `organization`, `contact`, and `bio` fields. It was applied to the verified XAMPP `project_bayani` database. No data was dropped. PHPUnit uses SQLite in memory.

Material CRUD/review, favorites, notifications, system settings, and admin user management screens are still prototype-only localStorage workflows. They are not persisted to MariaDB in this phase. Password reset, email verification, and account deletion are not implemented. Contributor routes are role-protected, but per-material ownership checks must be added when materials become database-backed in Phase 5.
