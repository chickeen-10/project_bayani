<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $curriculumId = DB::table('curricula')->where('code', 'K12-LEGACY')->value('id');
        if (!$curriculumId) return;

        // STEM, ABM, HUMSS, and GAS are strands under the legacy Academic track, not separate tracks.
        $incorrectTrackNames = [
            'Science, Technology, Engineering, and Mathematics (STEM)',
            'Accountancy, Business, and Management (ABM)',
            'Humanities and Social Sciences (HUMSS)',
            'General Academic Strand (GAS)',
        ];
        $ids = DB::table('tracks')->where('curriculum_id', $curriculumId)->whereIn('name', $incorrectTrackNames)->pluck('id');
        foreach ($ids as $id) {
            if (DB::table('materials')->where('track_id', $id)->exists() || DB::table('strands')->where('track_id', $id)->exists() || DB::table('curriculum_subjects')->where('track_id', $id)->exists()) continue;
            DB::table('tracks')->where('id', $id)->update(['status' => 'inactive', 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Keep corrected catalog rows inactive; administrators may have curated related data since deployment.
    }
};
