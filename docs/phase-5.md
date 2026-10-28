# Phase 5 database integration and prototype data handoff

## Data now stored in MariaDB

- Authenticated contributor and administrator material metadata, including status and ownership.
- Material review decisions and remarks.
- Categories, barangays, and historical eras.
- Activity records for material creation, editing, submission, and review.
- Existing profile and account data from Phase 4.

Public repository queries include only Approved records. New submissions belong to the authenticated contributor and begin as Draft or Pending. Reference records are not seeded; administrators must enter verified values.

## Data still in browser storage

The legacy `bayani.demo.v1` key may contain prototype materials, taxonomy, demo profiles/users, local notifications/activity, favorites, recent views, download history, feedback, and presentation settings. Temporary file bytes use `bayani.file.<token>` in sessionStorage. Nothing in this phase deletes, imports, or combines those values with MariaDB. Some non-integrated interface pages still use the prototype store, including preferences, favorites/recent activity, notifications, feedback, reports, system settings, user management, and legacy cleanup.

## Optional, reviewed import plan

There is no automatic migration or import command. If a contributor needs to retain real browser-created records:

1. Export or manually inventory only user-created records from the original browser profile. Keep a backup and leave legacy sample/demo rows out of the candidate file.
2. Review each material and identify its real registered owner. Demo usernames/roles are not identities; match owners only by confirmed account email. Never import demo credentials or browser role assignments. All imported accounts must sign up normally, and admin assignment continues through the authorized `bayani:grant-admin` command.
3. Resolve category, barangay, and era names against verified reference records. Flag spelling collisions and missing references for human resolution; do not invent place or history data.
4. Assign new database IDs and keep a separate old-ID to new-ID mapping for any later import of favorites or activity. Do not assume browser IDs are database IDs.
5. Import metadata into an isolated staging copy using a reviewed, idempotent command or transaction. Start imported submissions as Draft or Pending for individual review; never automatically publish them as Approved.
6. Validate row counts, ownership, relationships, duplicate handling, and rollback behavior. After explicit owner acceptance, run the import against the target database with a backup in place and retain the mapping/report.
7. Browser sessionStorage files are still outside this legacy-data import plan and can expire when the tab closes. Phase 6 file uploads are handled separately by Laravel private storage; old temporary browser files are not automatically imported.

Do not run this process against production until export integrity, account mapping, collision handling, and rollback are reviewed. No such import was performed for Phase 5.

## Phase 6 completion

Laravel file upload, private storage, MIME/extension/size checks, ownership/publication access rules, controlled previews/downloads, and replacement/removal behavior are implemented. Current database resources use persistent files on the private `bayani_uploads` disk; old browser-stored records and temporary bytes remain untouched and are not treated as server files. See [docs/file-storage.md](file-storage.md) for operating and backup details.
