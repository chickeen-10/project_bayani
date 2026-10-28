# Material submission and review workflow

Project BAYANI stores contributor material records, review decisions, and notification records in MariaDB. This workflow uses the existing `materials`, `material_reviews`, `notifications`, and `activity_logs` tables; Phase 7 does not require a schema migration.

## Statuses and transitions

| Current state | Allowed next state | Who can cause it |
| --- | --- | --- |
| Draft | Pending | Owner submits it |
| Pending | Approved, Revision Requested, Rejected | Administrator review |
| Revision Requested | Pending | Owner corrects and resubmits |
| Rejected | Pending | Owner corrects and resubmits |
| Approved | Pending | Administrator changes approved material metadata/file; it must be reviewed again |
| Draft, Pending, Revision Requested, Rejected, Approved | Archived | Owner or administrator archives; record is soft-deleted |
| Archived | None | Archived records are retained and excluded from normal pages |

Contributors cannot edit or replace files while a submission is Pending or Approved. They can edit Draft, Revision Requested, and Rejected materials. Draft intent is accepted only while the material is a Draft; other editable states must be resubmitted into Pending. Rejection and revision requests require administrator remarks. Approval remarks are optional.

## Review and publication

- The administrator queue is backed by `materials` and defaults to Pending. Search covers title/description and contributor name; filters include subject, grade, curriculum, contributor, and status.
- An administrator review detail shows material metadata, a securely served attachment, available decisions, and the full append-only review history.
- Only Approved, non-archived records are queryable from public repository, home, and public detail routes. File preview/download routes independently check Approved status, or require the owner contributor/administrator for private states.
- An administrator editing an Approved title, description, taxonomy, curriculum, subject/grade selections, or file moves the resource to Pending and clears `published_at`. Changing an Approved file from contributor controls is forbidden.
- Archiving sets `status=Archived`, clears publication time, and soft-deletes the record in one transaction. It is retained in the database and can be recovered by an authorized database operator; normal route model binding and public queries exclude it.

## Audit and notifications

Each administrator decision appends a `material_reviews` record with material, reviewer, decision, remarks, and timestamp. An `activity_logs` row records actor, previous/new status, review action, remarks, and timestamp. Archive and metadata re-review actions are also logged. History entries are not overwritten when a material is resubmitted.

Laravel database notifications are sent to active administrators when a contributor submits or resubmits, and to the contributor when the material is approved, revision is requested, or rejected. Notifications appear on the authenticated `/notifications` page. No email or SMS channel is configured or used.

## Authorization

- Guests can see only published materials and attachments.
- Contributors see and manage only their own submissions, and cannot make administrator decisions.
- Administrators can access the queue, review history, secure private previews, and repository management routes.
- Database feature tests use isolated SQLite and fake upload storage. They do not create example submissions in live MariaDB.

## Remaining work

Email/SMS, notification read-state actions, configurable review SLAs, multi-step moderation, and bulk review are outside this phase and remain for Phase 9 or later. Browser-visible verification remains outstanding for this run because Computer Use was stopped by the physical Escape key.
