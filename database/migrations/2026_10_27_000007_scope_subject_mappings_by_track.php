<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('curriculum_subjects', function (Blueprint $table): void {
            $table->index('curriculum_id', 'curr_sub_curriculum_id_index');
            $table->dropUnique('curr_sub_grade_unique');
            $table->foreignId('track_id')->nullable()->after('grade_level_id')->constrained()->restrictOnDelete();
            $table->foreignId('strand_id')->nullable()->after('track_id')->constrained()->restrictOnDelete();
            $table->unique(['curriculum_id', 'subject_id', 'grade_level_id', 'track_id', 'strand_id'], 'curr_sub_grade_track_strand_unique');
        });
    }

    public function down(): void
    {
        Schema::table('curriculum_subjects', function (Blueprint $table): void {
            $table->dropUnique('curr_sub_grade_track_strand_unique');
            $table->dropForeign(['strand_id']);
            $table->dropForeign(['track_id']);
            $table->dropColumn(['track_id', 'strand_id']);
            $table->unique(['curriculum_id', 'subject_id', 'grade_level_id'], 'curr_sub_grade_unique');
            $table->dropIndex('curr_sub_curriculum_id_index');
        });
    }
};
