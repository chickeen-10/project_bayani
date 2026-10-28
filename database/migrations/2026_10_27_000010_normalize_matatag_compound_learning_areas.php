<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('subjects')->insertOrIgnore([
            'name' => 'EPP/TLE', 'code' => 'MATATAG-EPP-TLE',
            'description' => 'Exact MATATAG curriculum guide title for the combined EPP/TLE learning area.',
            'status' => 'inactive', 'created_at' => $now, 'updated_at' => $now,
        ]);

        $curriculumId = DB::table('curricula')->where('code', 'MATATAG')->value('id');
        $gradeIds = DB::table('grade_levels')->pluck('id', 'code');
        $subjects = DB::table('subjects')->pluck('id', 'name');
        $newSubjectId = $subjects['EPP/TLE'];
        foreach (['G4' => '2024-2025', 'G5' => '2025-2026', 'G7' => '2024-2025'] as $gradeCode => $schoolYear) {
            DB::table('curriculum_subjects')->insertOrIgnore([
                'curriculum_id' => $curriculumId, 'subject_id' => $newSubjectId, 'grade_level_id' => $gradeIds[$gradeCode],
                'classification' => 'learning area', 'school_year' => $schoolYear, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        DB::table('curriculum_subjects')->insertOrIgnore([
            'curriculum_id' => $curriculumId, 'subject_id' => $subjects['Physical Education and Health'], 'grade_level_id' => $gradeIds['G5'],
            'classification' => 'learning area', 'school_year' => '2025-2026', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);

        $superseded = [
            ['Edukasyong Pantahanan at Pangkabuhayan (EPP)', 'G4'],
            ['Edukasyong Pantahanan at Pangkabuhayan (EPP)', 'G5'],
            ['Technology and Livelihood Education (TLE)', 'G5'],
            ['Technology and Livelihood Education (TLE)', 'G7'],
            ['Values Education', 'G5'],
            ['Physical Education', 'G5'],
            ['Health', 'G5'],
        ];
        foreach ($superseded as [$subjectName, $gradeCode]) {
            if (!isset($subjects[$subjectName], $gradeIds[$gradeCode])) continue;
            $mappingIds = DB::table('curriculum_subjects')->where('curriculum_id', $curriculumId)->where('subject_id', $subjects[$subjectName])->where('grade_level_id', $gradeIds[$gradeCode])->pluck('id');
            foreach ($mappingIds as $mappingId) {
                $used = DB::table('material_subject')->join('grade_level_material', 'material_subject.material_id', '=', 'grade_level_material.material_id')
                    ->where('material_subject.subject_id', $subjects[$subjectName])->where('grade_level_material.grade_level_id', $gradeIds[$gradeCode])->exists();
                if (!$used) DB::table('curriculum_subjects')->where('id', $mappingId)->update(['status' => 'inactive', 'updated_at' => $now]);
            }
        }

        DB::table('subjects')->whereIn('id', DB::table('curriculum_subjects')->where('status', 'active')->pluck('subject_id'))->update(['status' => 'active', 'updated_at' => $now]);
        DB::table('subjects')->where('status', 'active')->whereNotIn('id', DB::table('curriculum_subjects')->where('status', 'active')->pluck('subject_id'))->update(['status' => 'inactive', 'updated_at' => $now]);
    }

    public function down(): void
    {
        // Keep this corrective catalog change in place; materials may have been mapped to it after deployment.
    }
};
