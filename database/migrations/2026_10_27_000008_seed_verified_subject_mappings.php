<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $newSubjects = [
            'GMRC',
            'EPP/TLE',
            'Effective Communication / Mabisang Komunikasyon',
            'General Science',
            'Life and Career Skills',
            'Pag-aaral ng Kasaysayan at Lipunang Pilipino',
            'Oral Communication in Context',
            'Reading and Writing Skills',
            'Pagbasa at Pagsusuri ng Iba’t Ibang Teksto Tungo sa Pananaliksik',
        ];
        foreach ($newSubjects as $name) {
            DB::table('subjects')->insertOrIgnore([
                'name' => $name,
                'code' => 'VERIFIED-'.Str::upper(Str::substr(Str::slug($name), 0, 65)),
                'description' => 'Exact official curriculum guide title added during Phase 6. Applicability is limited to the mapped curriculum, grade, and school year.',
                'status' => 'inactive', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $curricula = DB::table('curricula')->pluck('id', 'code');
        $grades = DB::table('grade_levels')->pluck('id', 'code');
        $subjects = DB::table('subjects')->pluck('id', 'name');

        $track = function (string $curriculum, string $name, string $code) use ($curricula, $now): int {
            DB::table('tracks')->insertOrIgnore(['curriculum_id' => $curricula[$curriculum], 'name' => $name, 'code' => $code, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
            return (int) DB::table('tracks')->where('curriculum_id', $curricula[$curriculum])->where('name', $name)->value('id');
        };
        $academic = $track('K12-LEGACY', 'Academic', 'ACADEMIC');
        $tvl = $track('K12-LEGACY', 'Technical-Vocational-Livelihood (TVL)', 'TVL');
        $track('K12-LEGACY', 'Arts and Design', 'ARTS-DESIGN');
        $track('K12-LEGACY', 'Sports', 'SPORTS');
        foreach ([[$academic, 'STEM'], [$academic, 'ABM'], [$academic, 'HUMSS'], [$academic, 'GAS']] as [$parent, $name]) {
            DB::table('strands')->insertOrIgnore(['track_id' => $parent, 'name' => $name, 'code' => Str::upper($name), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        }
        $stemStrand = (int) DB::table('strands')->where('track_id', $academic)->where('name', 'STEM')->value('id');
        $track('STRENGTHENED-SHS', 'Academic', 'ACADEMIC');
        $track('STRENGTHENED-SHS', 'TechPro', 'TECHPRO');

        $mapping = function (string $curriculum, string $subject, string $grade, ?string $schoolYear = null, string $classification = 'learning area', ?int $trackId = null, ?int $strandId = null) use ($curricula, $grades, $subjects, $now): void {
            if (!isset($curricula[$curriculum], $grades[$grade], $subjects[$subject])) return;
            DB::table('curriculum_subjects')->insertOrIgnore([
                'curriculum_id' => $curricula[$curriculum], 'subject_id' => $subjects[$subject], 'grade_level_id' => $grades[$grade],
                'track_id' => $trackId, 'strand_id' => $strandId, 'classification' => $classification, 'school_year' => $schoolYear,
                'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
            ]);
        };

        // MATATAG Phase 1: official guides and DepEd training pages identify the learning areas by grade.
        foreach (['Kindergarten' => 'K', 'Grade 1' => 'G1'] as $unused => $grade) {
            foreach (['Mathematics', 'GMRC', 'Makabansa'] as $subject) $mapping('MATATAG', $subject, $grade, '2024-2025');
        }
        foreach (['G4' => ['Filipino','English','Science','Mathematics','Araling Panlipunan','MAPEH','EPP/TLE','GMRC'], 'G7' => ['Filipino','English','Science','Mathematics','Araling Panlipunan','MAPEH','EPP/TLE','GMRC']] as $grade => $learningAreas) {
            foreach ($learningAreas as $subject) $mapping('MATATAG', $subject, $grade, '2024-2025');
        }
        // Phase 2 grade schedules come from DepEd's procurement announcement; only explicit rows are added.
        foreach (['G2' => ['Makabansa','GMRC','Filipino','English','Mathematics'], 'G5' => ['Mathematics','English','Filipino','EPP/TLE','GMRC','Araling Panlipunan','Physical Education and Health','Science','Music','Arts']] as $grade => $learningAreas) {
            foreach (array_unique($learningAreas) as $subject) $mapping('MATATAG', $subject, $grade, '2025-2026');
        }

        // Legacy K-10 guides remain separately selectable for materials created under the prior curriculum.
        foreach (['Filipino','English','Mathematics','Araling Panlipunan','Edukasyon sa Pagpapakatao (EsP)','MAPEH'] as $subject) {
            foreach (range(1, 10) as $number) $mapping('K12-LEGACY', $subject, 'G'.$number);
        }
        foreach (range(3, 10) as $number) $mapping('K12-LEGACY', 'Science', 'G'.$number);
        foreach (range(1, 3) as $number) $mapping('K12-LEGACY', 'Mother Tongue', 'G'.$number);
        foreach (range(4, 6) as $number) $mapping('K12-LEGACY', 'Edukasyong Pantahanan at Pangkabuhayan (EPP)', 'G'.$number);
        foreach (range(7, 10) as $number) $mapping('K12-LEGACY', 'Technology and Livelihood Education (TLE)', 'G'.$number);

        // Legacy SHS common/core subjects are mapped only to the grade levels listed in DepEd's official scheduling guide.
        $legacyCommon = [
            'Oral Communication in Context' => 'G11', 'Reading and Writing Skills' => 'G11',
            'Komunikasyon at Pananaliksik sa Wika at Kulturang Pilipino' => 'G11',
            'Pagbasa at Pagsusuri ng Iba’t Ibang Teksto Tungo sa Pananaliksik' => 'G12',
            'Contemporary Philippine Arts from the Regions' => 'G11',
            '21st Century Literature from the Philippines and the World' => 'G12',
            'General Mathematics' => 'G11', 'Statistics and Probability' => 'G12',
            'Media and Information Literacy' => 'G11', 'Physical Science' => 'G11',
            'Physical Education and Health' => 'G11', 'Personal Development' => 'G11',
            'Understanding Culture, Society and Politics' => 'G12',
            'Introduction to Philosophy of the Human Person' => 'G12',
        ];
        foreach ($legacyCommon as $subject => $grade) $mapping('K12-LEGACY', $subject, $grade, null, 'core');
        $mapping('K12-LEGACY', 'Earth Science', 'G11', null, 'specialized', $academic, $stemStrand);
        $mapping('K12-LEGACY', 'Entrepreneurship', 'G12', null, 'core');
        $mapping('K12-LEGACY', 'Work Immersion', 'G12', null, 'applied');

        // Strengthened SHS core is grade 11 for full implementation SY 2026-2027.
        foreach (['Effective Communication / Mabisang Komunikasyon','General Mathematics','General Science','Life and Career Skills','Pag-aaral ng Kasaysayan at Lipunang Pilipino'] as $subject) {
            $mapping('STRENGTHENED-SHS', $subject, 'G11', '2026-2027', 'core');
        }

        DB::table('subjects')->whereIn('id', DB::table('curriculum_subjects')->where('status', 'active')->pluck('subject_id'))->update(['status' => 'active', 'updated_at' => $now]);
        DB::table('curricula')->whereIn('id', DB::table('curriculum_subjects')->where('status', 'active')->pluck('curriculum_id'))->update(['status' => 'active', 'updated_at' => $now]);
    }

    public function down(): void
    {
        // Verified curriculum references must be preserved if materials were linked after deployment.
    }
};
