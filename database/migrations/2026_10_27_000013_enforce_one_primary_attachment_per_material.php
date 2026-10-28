<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_attachments', function (Blueprint $table): void {
            $table->foreignId('primary_material_id')->nullable()->unique()->after('is_primary')->constrained('materials')->cascadeOnDelete();
        });
        DB::table('material_attachments')->where('is_primary', true)->orderBy('id')->get(['id', 'material_id'])->each(
            fn ($attachment) => DB::table('material_attachments')->where('id', $attachment->id)->update(['primary_material_id' => $attachment->material_id])
        );
    }

    public function down(): void
    {
        Schema::table('material_attachments', function (Blueprint $table): void {
            $table->dropForeign(['primary_material_id']);
            $table->dropUnique(['primary_material_id']);
            $table->dropColumn('primary_material_id');
        });
    }
};
