# Material file storage

## Supported uploads and size

The contributor and administrator material forms accept PDF, DOCX, PPTX, XLSX, JPG/JPEG, PNG, and WEBP uploads. The default limit is 20 MB per file, set by `BAYANI_MAX_UPLOAD_MB` (also available in `.env.example`). Check PHP `upload_max_filesize` and `post_max_size` if configuring a higher application limit.

The server validates upload status, allowed extension, detected MIME type, and size. OOXML files are opened as ZIP archives and must contain the expected document, presentation, or workbook part plus `[Content_Types].xml`. Filenames on disk are UUIDs with the validated extension; the original basename is stored separately for downloads. A SHA-256 checksum and MIME/size metadata are stored in MariaDB. Binaries are never stored in the database.

Authenticated profile pictures are a separate upload flow. PNG, JPG/JPEG, and WEBP are limited to 10 MB, validated by extension and MIME, decoded and re-encoded with GD, and resized to at most 1,024 pixels on the longest edge. Images above 10,000 pixels per side or 40 megapixels total are rejected. PHP `upload_max_filesize` must be at least `10M` and `post_max_size` at least `12M`; GD must support JPEG, PNG, and WEBP. Existing profile image files are retained when a replacement is uploaded.

## Disk and access control

The `bayani_uploads` Laravel filesystem disk stores files under `storage/app/private/materials`, outside the public web root. Controllers serve bytes only after checking the material's status and the signed-in user's role/ownership. Approved resources are available to public visitors; drafts, pending, rejected, and revision-requested resources are available only to the owner contributor or an administrator. Storage paths and checksums are hidden from serialized material data.

Preview/download routes do not expose storage URLs. Paths must be under the controlled `materials/` directory and the file must exist. Responses use safe MIME types, `X-Content-Type-Options: nosniff`, and private/no-store cache headers. Download names use the stored original filename. CSRF applies to upload, replacement, and removal forms/actions.

## Replacement, removal, retention

Contributors can replace or remove files only on their own editable Draft, Pending, Rejected, or Revision Requested records. They cannot change an Approved record. Administrators may replace or remove any record's file; changing an Approved record's attachment moves it to Pending and clears its published timestamp so the new bytes are reviewed. A replacement is stored before the database transaction; if the transaction fails, the new file is removed and the old file remains. The old file is removed only after successful update and only if no material (including archived/soft-deleted records) refers to it. Archiving a material keeps its file for retention.

## Preview support

- PDF files render inline in the browser with scrolling/zoom supplied by the PDF viewer. PDF cover thumbnails are rasterized from page one by the locally installed PDF.js and `@napi-rs/canvas` renderer.
- Image files display the uploaded image itself and use it for the repository thumbnail.
- XLSX previews parse bounded OOXML worksheet content into scrollable text cells with worksheet tabs and zoom. Only saved/cached cell values are shown; formulas are not evaluated.
- DOCX previews parse paragraphs, headings, lists, and tables into escaped, read-only HTML. Embedded HTML, macros, and scripts are not executed.
- PPTX is clearly marked download-only because the application has no secure, reliable slide renderer/converter.
- Generated thumbnails are stored privately under the `thumbnails/` prefix and keyed by source SHA-256, title, renderer version, and format. Changing source content/title produces a new cache key; old unreferenced thumbnail files are removed after successful replacement.
- Existing records can be processed with `php artisan bayani:backfill-thumbnails`. It processes stored attachments without requiring reupload and reports generated/reused, original image, download-only, and failed counts.
- Preview and thumbnail routes apply the same approved-public or owner/administrator authorization as attachment access. The original download is a separate attachment response; Office preview responses are read-only HTML/JSON-rendered content.
- Safe processing bounds limit upload size, archive entries and total uncompressed bytes, XML member size, sheet count, rows, columns, cells, text, PDF size, renderer memory, and runtime. ZIP members are never extracted to disk.

## Educational catalog status

MariaDB catalog totals and source limitations are recorded in [the DepEd catalog source register](deped-catalog-sources.md). The four baseline categories are Project BAYANI organizational groupings, not DepEd classifications. `ReferenceDataSeeder` inserts them idempotently. No new subject or curriculum mapping was seeded in Phase 8.3 because the existing active rows require further exact curriculum-guide and school-year verification.

## Configuration and checks

Keep the `bayani_uploads` disk private. Do not symlink it into `public/storage`. Back up both the MariaDB database and `storage/app/private/materials`; database-only backups do not include the binary files. Automated feature tests use fake storage and cover valid/invalid uploads, ownership, private/public access, missing files, PDF/image/Office previews, thumbnail authorization and cache behavior, replacement, removal, storage failures, and subject-grade selection. The current implementation and verification status are recorded in [PROGRESS.md](../PROGRESS.md).
