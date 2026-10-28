<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('curricula')->where('code','STRENGTHENED-SHS')->update([
            'description'=>'DepEd full implementation begins with incoming Grade 11 learners in SY 2026–2027; Grade 12 learners in non-pilot schools continue the existing SHS curriculum, while prior pilot cohorts continue the pilot. Curate school-year mappings from current issuances.',
            'source_url'=>'https://www.deped.gov.ph/wp-content/uploads/DM-12-s.-2026_Full-Implementation-of-the-Strengthened-Senior-High-School-Curriculum-in-School-Year-2026-2027.pdf',
            'updated_at'=>now(),
        ]);
    }
    public function down(): void
    {
        DB::table('curricula')->where('code','STRENGTHENED-SHS')->update([
            'description'=>'Phased/pilot SHS curriculum. Associate offerings with applicable grade and rollout only after verification.',
            'source_url'=>'https://www.deped.gov.ph/strengthened-shs-program/', 'updated_at'=>now(),
        ]);
    }
};
