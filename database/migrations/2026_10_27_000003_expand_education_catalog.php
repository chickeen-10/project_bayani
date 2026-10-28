<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('curricula', function (Blueprint $table): void {
            $table->id(); $table->string('name')->unique(); $table->string('code')->unique();
            $table->text('description')->nullable(); $table->string('source_url', 2048)->nullable();
            $table->string('status', 20)->default('active')->index(); $table->timestamps();
        });
        Schema::create('grade_levels', function (Blueprint $table): void {
            $table->id(); $table->string('name')->unique(); $table->string('code', 20)->unique();
            $table->unsignedTinyInteger('sequence')->unique(); $table->string('stage', 30)->index(); $table->timestamps();
        });
        Schema::create('subjects', function (Blueprint $table): void {
            $table->id(); $table->string('name'); $table->string('code')->nullable()->unique();
            $table->text('description')->nullable(); $table->string('status', 20)->default('active')->index(); $table->timestamps();
            $table->unique('name');
        });
        Schema::create('curriculum_subjects', function (Blueprint $table): void {
            $table->id(); $table->foreignId('curriculum_id')->constrained()->restrictOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->foreignId('grade_level_id')->constrained()->restrictOnDelete();
            $table->string('classification', 40)->nullable(); $table->string('school_year', 20)->nullable();
            $table->string('status', 20)->default('active')->index(); $table->timestamps();
            $table->unique(['curriculum_id', 'subject_id', 'grade_level_id'], 'curr_sub_grade_unique');
            $table->index(['grade_level_id', 'curriculum_id', 'status']);
        });
        Schema::create('tracks', function (Blueprint $table): void {
            $table->id(); $table->foreignId('curriculum_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name'); $table->string('code')->nullable(); $table->string('status', 20)->default('active')->index(); $table->timestamps();
            $table->unique(['curriculum_id', 'name']);
        });
        Schema::create('strands', function (Blueprint $table): void {
            $table->id(); $table->foreignId('track_id')->constrained()->restrictOnDelete();
            $table->string('name'); $table->string('code')->nullable(); $table->string('status', 20)->default('active')->index(); $table->timestamps();
            $table->unique(['track_id', 'name']);
        });
        Schema::create('material_types', function (Blueprint $table): void {
            $table->id(); $table->string('name')->unique(); $table->text('description')->nullable();
            $table->string('status', 20)->default('active')->index(); $table->timestamps();
        });
        Schema::table('materials', function (Blueprint $table): void {
            $table->foreignId('curriculum_id')->nullable()->after('historical_era_id')->constrained()->restrictOnDelete();
            $table->foreignId('material_type_id')->nullable()->after('curriculum_id')->constrained()->restrictOnDelete();
            $table->foreignId('track_id')->nullable()->after('material_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('strand_id')->nullable()->after('track_id')->constrained()->restrictOnDelete();
            $table->string('topic', 255)->nullable()->after('description');
            $table->text('keywords')->nullable()->after('topic');
        });
        Schema::create('material_subject', function (Blueprint $table): void {
            $table->id(); $table->foreignId('material_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete(); $table->timestamps();
            $table->unique(['material_id', 'subject_id']);
        });
        Schema::create('grade_level_material', function (Blueprint $table): void {
            $table->id(); $table->foreignId('material_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grade_level_id')->constrained()->restrictOnDelete(); $table->timestamps();
            $table->unique(['material_id', 'grade_level_id']);
        });

        DB::table('grade_levels')->insert(collect([
            ['Kindergarten','K','kindergarten'], ['Grade 1','G1','elementary'], ['Grade 2','G2','elementary'],
            ['Grade 3','G3','elementary'], ['Grade 4','G4','elementary'], ['Grade 5','G5','elementary'], ['Grade 6','G6','elementary'],
            ['Grade 7','G7','junior_high'], ['Grade 8','G8','junior_high'], ['Grade 9','G9','junior_high'], ['Grade 10','G10','junior_high'],
            ['Grade 11','G11','senior_high'], ['Grade 12','G12','senior_high'],
        ])->values()->map(fn ($g, $i) => ['name'=>$g[0], 'code'=>$g[1], 'stage'=>$g[2], 'sequence'=>$i+1, 'created_at'=>now(), 'updated_at'=>now()])->all());
        DB::table('material_types')->insert(collect([
            'Lesson Plan','Detailed Lesson Plan','Daily Lesson Log','Learning Activity Sheet','Worksheet','Module','Self-Learning Module',
            'Learning Manual',"Teacher's Guide",'Learner Material','Presentation','Assessment','Quiz','Examination','Reviewer',
            'Reading Material','Research Paper','Historical Document','Image Resource','Audio Resource','Video Resource','Other Educational Resource',
        ])->map(fn ($name) => ['name'=>$name, 'created_at'=>now(), 'updated_at'=>now()])->all());
        DB::table('curricula')->insert([
            ['name'=>'K to 12 Basic Education Curriculum (legacy)','code'=>'K12-LEGACY','description'=>'Legacy K to 12 curriculum. Subject and grade offerings must be mapped by an administrator from authoritative guides.','source_url'=>'https://www.deped.gov.ph/wp-content/uploads/2019/01/DO_s2019_021.pdf','created_at'=>now(),'updated_at'=>now()],
            ['name'=>'MATATAG Curriculum','code'=>'MATATAG','description'=>'Phased K to Grade 10 curriculum; do not infer adoption for all grade levels.','source_url'=>'https://www.deped.gov.ph/matatagcurriculumk147/','created_at'=>now(),'updated_at'=>now()],
            ['name'=>'Strengthened Senior High School Curriculum','code'=>'STRENGTHENED-SHS','description'=>'Phased/pilot SHS curriculum. Associate offerings with applicable grade and rollout only after verification.','source_url'=>'https://www.deped.gov.ph/strengthened-shs-program/','created_at'=>now(),'updated_at'=>now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_level_material'); Schema::dropIfExists('material_subject');
        Schema::table('materials', function (Blueprint $table): void {
            $table->dropForeign(['curriculum_id']); $table->dropForeign(['material_type_id']); $table->dropForeign(['track_id']); $table->dropForeign(['strand_id']);
            $table->dropColumn(['curriculum_id','material_type_id','track_id','strand_id','topic','keywords']);
        });
        foreach (['material_types','strands','tracks','curriculum_subjects','subjects','grade_levels','curricula'] as $table) Schema::dropIfExists($table);
    }
};
