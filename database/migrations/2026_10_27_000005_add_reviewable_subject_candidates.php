<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $names=[
            'Filipino','English','Mathematics','Science','Araling Panlipunan','Edukasyon sa Pagpapakatao (EsP)',
            'Good Manners and Right Conduct (GMRC)','Values Education','Music','Arts','Physical Education','Health','MAPEH',
            'Edukasyong Pantahanan at Pangkabuhayan (EPP)','Technology and Livelihood Education (TLE)','Mother Tongue',
            'Edukasyon sa Pagpapalakas ng Katawan','Reading and Literacy','Makabansa','Oral Communication','Reading and Writing',
            'Komunikasyon at Pananaliksik sa Wika at Kulturang Pilipino','Pagbasa at Pagsusuri ng Iba’t Ibang Teksto Tungo sa Pananaliksik',
            '21st Century Literature from the Philippines and the World','Contemporary Philippine Arts from the Regions',
            'General Mathematics','Statistics and Probability','Earth Science','Physical Science','General Biology','General Chemistry','General Physics',
            'Personal Development','Understanding Culture, Society and Politics','Introduction to Philosophy of the Human Person',
            'Media and Information Literacy','Physical Education and Health','Practical Research','Inquiries, Investigations and Immersion',
            'Empowerment Technologies','Entrepreneurship','Work Immersion','STEM specialized subjects (administrator-defined)',
            'ABM specialized subjects (administrator-defined)','HUMSS specialized subjects (administrator-defined)',
            'GAS electives (administrator-defined)','TVL specializations (administrator-defined)',
            'Arts and Design track subjects (administrator-defined)','Sports track subjects (administrator-defined)',
        ];
        $rows=collect($names)->map(fn($name)=>['name'=>$name,'code'=>'CANDIDATE-'.Str::upper(Str::substr(Str::slug($name),0,65)),'description'=>'Candidate from the expanded Project BAYANI scope. Verify the official curriculum guide and grade/school-year applicability, then map and activate before contributor use.','status'=>'inactive','created_at'=>now(),'updated_at'=>now()])->all();
        DB::table('subjects')->insertOrIgnore($rows);
    }
    public function down(): void
    {
        // Candidate rows may have been reviewed or linked after deployment. Preserve them on rollback.
    }
};
