# DepEd Catalog Source Register

Reviewed 2026-10-27. This register distinguishes DepEd-published curriculum information from Project BAYANI organizational metadata. It is not a claim that every catalog row or mapping has been verified.

## Authoritative references

| Source | URL | What it supports | Limits |
|---|---|---|---|
| DepEd, Revised K to 10 Curriculum landing page | https://www.deped.gov.ph/revised-k-to-10-curriculum/ | Links to MATATAG curriculum guides and explains the phased rollout beginning in SY 2024-2025. | A curriculum guide must be checked for the exact subject and grade before activating a mapping. The landing page itself does not support every grade-subject combination. |
| DepEd, MATATAG curriculum guides, Kindergarten / Grades 1, 4, 7 | https://www.deped.gov.ph/matatagcurriculumk147/ | Official guide access for the first rollout cohort. | Do not infer rollout years or applicability to other grades from this cohort alone. |
| DepEd, Strengthened SHS Program | https://www.deped.gov.ph/strengthened-shs-program/ | Official program page and curriculum-guide access point. | Check the currently applicable guide/issuance and school-year cohort before mapping Grade 11 or Grade 12 resources. |
| DepEd Memorandum No. 048, s. 2025, Pilot Implementation of the Strengthened Senior High School Curriculum for Grade 11 in SY 2025-2026 | https://www.deped.gov.ph/wp-content/uploads/DM-48-s.-2025_-Pilot-Implementation-of-the-Strengthened-Senior-High-School-Curriculum-for-Grade-11-in-School-Year-2025-2026.pdf | Pilot-only transition details, the Academic and TechPro pathways, core subjects, elective clusters, and initial Grade 11 offerings. | It explicitly describes pilot implementation and states some subsequent-year elective lists would be issued separately. Do not treat its pilot lists as the complete full-implementation catalog. |
| DepEd Learning Resource Portal (LRMDS) | https://lrmds.deped.gov.ph/ | Shows resource labels used by the portal, including Learning Material, Lesson Plan, Learning Guide, Learning Module, and Full course. | Portal resource labels describe the portal; they do not establish a universal required taxonomy for Project BAYANI. |
| DepEd, Academic Track | https://www.deped.gov.ph/k-to-12/about/k-to-12-basic-education-curriculum/academic-track/ | Legacy SHS Academic Track context. | Use in the legacy curriculum only, not as the Strengthened SHS model. |

## Catalog snapshot from the configured MariaDB

The following counts were read directly from the configured `mysql` connection on 2026-10-27. No rows were changed during the audit.

| Catalog | Total | Active | Inactive | Audit note |
|---|---:|---:|---:|---|
| Curricula | 3 | 3 | 0 | Legacy K to 12, MATATAG, and Strengthened SHS. |
| Grade levels | 13 | n/a | n/a | Kindergarten through Grade 12. |
| Subjects | 57 | 36 | 21 | Active status alone does not prove that every active name and mapping has been checked against a guide. |
| Curriculum-grade-subject mappings | 142 | 137 | 5 | The 137 active mappings still need a full row-by-row guide and school-year audit before this catalog can be called complete. |
| Tracks | 10 | 6 | 4 | Legacy SHS and Strengthened SHS are modeled separately; legacy strands remain distinct from Strengthened SHS elective clusters. |
| Strands | 4 | 4 | 0 | Presently the legacy Academic strands. |
| Material types | 22 | 22 | 0 | Application choices. Several labels align with terms used in DepEd resources, but the entire list is not asserted as an official DepEd taxonomy. |
| Categories | 4 | 4 | 0 | Four repeatably seeded Project BAYANI groupings; they are not DepEd classifications. The table was empty before the Phase 8.3 seeder was applied. |
| Retired place / era metadata catalogs | 0 / 0 | 0 / 0 | 0 / 0 | Removal is prepared; the live schema remains unchanged pending explicit approval. |
| Materials | 1 | n/a | n/a | The local homepage displayed one approved resource. The initial count query did not calculate a status distribution. |

## Resource type and category policy

The LR Portal explicitly describes Learning Material, Lesson Plan, Learning Guide, Learning Module, and Full course. Project BAYANI's material types remain its own operational choices unless a source above directly supports a label. Material Type should capture the specific format or resource kind. The baseline categories `Teaching and Planning`, `Learner Activities`, `Assessment`, and `Reference and Enrichment` are Project BAYANI organizational groupings only; they are seeded repeatably and must not be presented as official DepEd classifications.

## Unverified items and required follow-up

- Do not activate any inactive subject candidate solely from its name. Confirm exact guide title, grade, curriculum, and school-year applicability first.
- The existing active subject list and 137 active mappings are not certified complete by this audit. A grade-by-grade reconciliation against the applicable MATATAG, legacy K to 12, and strengthened SHS guides remains necessary.
- The Strengthened SHS catalog has Academic and TechPro tracks. The pilot memorandum is not sufficient evidence for a full Grade 12 elective catalog. Resolve current guide and full-implementation references before adding clusters or electives to contributor selectors.
- Existing active legacy Academic strand rows must remain classified as strands. Strengthened SHS elective clusters are a separate catalog concept; the present schema has no dedicated cluster entity.
- A current local database dump and copy of private uploads were created under ignored `storage/app/backups/` on 2026-10-27. The dump contains definitions for all 21 tables, includes the materials rows, and has SHA-256 recorded in the phase log; the one uploaded file's copied SHA-256 matched its source. This backup has not been restored into a separate MariaDB instance, so it is integrity-checked but not restore-tested. Do not treat it as production backup evidence.
- Phase 8.3A snapshot: both retired catalogs contain 0 rows and material references are 0. The database has 2 materials (1 soft-deleted), 2 users, 0 favorites, 2 reviews, and 3 notifications. A consistent backup was restored into an isolated temporary database and verified; schema removal remains unapplied pending explicit owner approval.
