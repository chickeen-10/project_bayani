# Education catalog and subject mappings

The normalized catalog supports Kindergarten through Grade 12, separate curriculum versions, school-year rollouts, and applicable senior-high tracks and strands. Catalog mappings are an administrator-maintained source of truth; public and contributor forms validate the selected curriculum, subject, grade, track, and strand on the server.

## Phase 6 verified mappings

The prior 49 candidates were reviewed. Exact curriculum-guide titles and source-backed grade offerings were activated; unclear candidates remain inactive. New exact subjects were added where the original candidate was an alias or placeholder. At the current MariaDB checkpoint there are 57 subjects, 36 active, and 137 active curriculum/subject/grade mappings. There are no material records to backfill or alter.

MATATAG mappings include:

- Kindergarten and Grade 1: Mathematics, GMRC, and Makabansa where those names appear in the official guides.
- Grade 2, SY 2025–2026: Makabansa, GMRC, Filipino, English, and Mathematics.
- Grade 4, SY 2024–2025: Filipino, English, Science, Mathematics, Araling Panlipunan, MAPEH, EPP/TLE, and GMRC.
- Grade 5, SY 2025–2026: Mathematics, English, Filipino, EPP/TLE, GMRC, Araling Panlipunan, Physical Education and Health, Science, Music, and Arts.
- Grade 7, SY 2024–2025: Filipino, English, Science, Mathematics, Araling Panlipunan, MAPEH, EPP/TLE, and GMRC.

These are explicit guide-backed offerings, not a rule that every subject applies to every MATATAG grade. The Phase 1 guides and Grade 2/5 procurement materials provide the grade-specific lists. MATATAG Grades 3, 6, 8, 9, and 10 remain unmapped in this seed until exact subject-guide mappings are verified. The current Phase 3 rollout grade schedule alone is not used to infer every subject.

Legacy K–10 mappings preserve Filipino, English, Mathematics, Araling Panlipunan, EsP, and MAPEH for Grades 1–10; Science for Grades 3–10; Mother Tongue for Grades 1–3; EPP for Grades 4–6; and TLE for Grades 7–10. Mother Tongue and EsP are distinct legacy curriculum entries. The Mother Tongue mapping is retained for existing historical resources; applicability under the later Mother Tongue policy must be checked for a school’s current language-of-instruction context.

Legacy SHS mappings cover only the subject/grade pairs listed in the DepEd SHS scheduling references. Academic is modeled as a track with STEM, ABM, HUMSS, and GAS strands. Earth Science is mapped specifically to Academic/STEM/Grade 11. The newer Strengthened SHS has Academic and TechPro tracks, without reusing legacy strands. Its five verified core subjects are mapped to Grade 11 for SY 2026–2027. The new curriculum's Grade 12 pilot and legacy non-pilot Grade 12 remain distinct; no Grade 12 Strengthened core subjects are inferred from the Grade 11 guide.

The exact new subjects added are `GMRC`, `EPP/TLE`, `Effective Communication / Mabisang Komunikasyon`, `General Science`, `Life and Career Skills`, `Pag-aaral ng Kasaysayan at Lipunang Pilipino`, `Oral Communication in Context`, and `Reading and Writing Skills`. The corrected apostrophe in the official legacy SHS title “Pagbasa at Pagsusuri ng Iba’t Ibang Teksto Tungo sa Pananaliksik” is preserved in the database.

Inactive candidates include broad administrator-defined track placeholders, old aliases that do not match the current guide title, and offerings whose exact grade, school year, track, or strand guide was not verified. The current inactive set is:

- ABM specialized subjects (administrator-defined)
- Arts and Design track subjects (administrator-defined)
- Edukasyon sa Pagpapalakas ng Katawan
- Empowerment Technologies
- GAS electives (administrator-defined)
- General Biology, General Chemistry, and General Physics
- Good Manners and Right Conduct (GMRC) (long alias; canonical `GMRC` is used)
- HUMSS specialized subjects (administrator-defined)
- Inquiries, Investigations and Immersion
- Oral Communication (short alias; canonical guide title used)
- Practical Research
- Reading and Literacy
- Reading and Writing (short alias; canonical guide title used)
- Sports track subjects (administrator-defined)
- STEM specialized subjects (administrator-defined)
- TVL specializations (administrator-defined)
- Values Education (not separately mapped where DepEd's source lists the combined GMRC/Values Education area)
- Physical Education and Health components `Health` and `Physical Education` (not duplicated beside the combined title)

The administrator catalog still permits new subjects and mappings to be reviewed and added. Active status alone is not treated as proof: an active subject must have an active verified curriculum mapping. Selected subjects are validated against every selected grade; track/strand-specific mappings also require the matching track/strand.

## Official DepEd references

- [MATATAG Phase 1 curriculum guides](https://www.deped.gov.ph/matatagcurriculumk147/) and [DepEd training catalog describing Phase 1 grade offerings](https://training.deped.gov.ph/course/index.php?categoryid=210): official Kindergarten, Grade 1, Grade 4, and Grade 7 guide coverage and learning-area lists.
- [DepEd Grade 2, 5, and 8 learning-resource procurement announcement](https://www.deped.gov.ph/2024/04/08/announcement-for-the-development-printing-and-delivery-of-textbooks-and-teachers-manuals-for-grades-2-5-and-8/): lists the Grade 2 learning areas and the Grade 5/8 textbook and teacher-manual learning-area scope for Phase 2.
- [MATATAG EPP/TLE Curriculum Guide, Grades 4–10](https://www.deped.gov.ph/wp-content/uploads/MATATAG-EPP_TLE-CG-2023-Grades-4-10-1.pdf): supports the combined EPP/TLE catalog title for the applicable MATATAG grade band.
- [K to 12 Science Curriculum Guide](https://www.deped.gov.ph/wp-content/uploads/2019/01/Science-CG_with-tagged-sci-equipment_revised.pdf), [Filipino Curriculum Guide](https://www.deped.gov.ph/wp-content/uploads/2019/01/Filipino-CG.pdf), [Araling Panlipunan Curriculum Guide](https://www.deped.gov.ph/wp-content/uploads/2019/01/AP-CG.pdf), [EsP Curriculum Guide](https://www.deped.gov.ph/wp-content/uploads/2019/01/ESP-CG.pdf), and [Mother Tongue Curriculum Guide, Grades 1–3](https://www.deped.gov.ph/wp-content/uploads/2019/01/Mother-Tongue-CG.pdf): legacy subject and grade scopes.
- [DepEd Strengthened SHS Program and current curriculum guides](https://www.deped.gov.ph/strengthened-shs-program/): exact Strengthened core titles, track structure, and published approved guides.
- [Strengthened SHS shaping paper](https://www.deped.gov.ph/wp-content/uploads/SSHS-SHAPING-PAPER-BCD-a_o-May-22.pdf): initial core grade-level listing and elective grade coverage.
- [DepEd Memorandum 012, s. 2026](https://www.deped.gov.ph/wp-content/uploads/DM-12-s.-2026_Full-Implementation-of-the-Strengthened-Senior-High-School-Curriculum-in-School-Year-2026-2027.pdf) and [DepEd Memorandum 048, s. 2025](https://www.deped.gov.ph/wp-content/uploads/DM-48-s.-2025_-Pilot-Implementation-of-the-Strengthened-Senior-High-School-Curriculum-for-Grade-11-in-School-Year-2025-2026.pdf): current full-implementation and preceding pilot cohorts.
- [Legacy K to 12 SHS curriculum policy, DepEd Order 021, s. 2019](https://www.deped.gov.ph/wp-content/uploads/2019/01/DO_s2019_021.pdf), plus official [HUMSS subject scheduling](https://www.deped.gov.ph/wp-content/uploads/2019/01/Humanities-and-Social-Sciences-HUMSS-Strand.pdf) and [STEM subject scheduling](https://www.deped.gov.ph/wp-content/uploads/2019/01/Science-Technology-Engineering-and-Mathematics-STEM-Strand.pdf).
- [DepEd Memorandum 059, s. 2025](https://www.deped.gov.ph/wp-content/uploads/DM_s2025_059r.pdf) disseminates the implementing rules for RA 12027 on Mother Tongue use and optional monolingual-class implementation.

## Schema and backup

`curriculum_subjects` stores `curriculum_id`, `subject_id`, `grade_level_id`, optional `track_id` and `strand_id`, classification, applicable school year, and status. The uniqueness key includes the curriculum, subject, grade, track, and strand. Material subject and grade pivots remain unchanged.

Before the Phase 6 MySQL migrations, MariaDB `project_bayani` was backed up with `mysqldump --single-transaction --routines --triggers` to `storage/app/backups/project_bayani_pre_phase6_file_storage_20261027.sql` (45,867 bytes). Migrations 000006–000010 are additive. Existing user and material records are preserved; the live checkpoint began with one user and no materials.

## Current limitations

- MATATAG Phase 2 and Phase 3 exact guides are not fully mapped across every subject/grade; only cited exact offerings are active. New DepEd guides should be added through administrator-reviewed mappings.
- Strengthened SHS Grade 12 pilot mappings and legacy Grade 12 alternatives still need exact current guide/year mapping.
- Legacy SHS specialized and track/strand-specific offerings remain pending where a specific official guide was not verified.
