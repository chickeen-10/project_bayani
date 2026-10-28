<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_attachments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('material_id')->constrained()->cascadeOnDelete();
            $table->string('original_filename');
            $table->string('stored_path');
            $table->string('storage_disk', 40)->default('bayani_uploads');
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('file_size');
            $table->char('sha256', 64)->nullable();
            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->string('thumbnail_path')->nullable();
            $table->timestamps();
            $table->index(['material_id', 'display_order']);
            $table->index(['material_id', 'is_primary']);
        });

        // Preserve the existing material IDs, metadata, and private file bytes.
        // Legacy columns remain in place so existing URLs and clients keep working.
        DB::table('materials')->whereNotNull('file_path')->orderBy('id')->get()->each(function ($material): void {
            $exists = DB::table('material_attachments')->where('material_id', $material->id)
                ->where('stored_path', $material->file_path)->exists();
            if ($exists) return;
            DB::table('material_attachments')->insert([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'material_id' => $material->id,
                'original_filename' => $material->original_filename ?: basename($material->file_path),
                'stored_path' => $material->file_path,
                'storage_disk' => $material->file_disk ?: 'bayani_uploads',
                'mime_type' => $material->file_type,
                'file_size' => $material->file_size ?: 0,
                'sha256' => $material->file_sha256,
                'display_order' => 0,
                'is_primary' => true,
                'thumbnail_path' => $material->thumbnail_path,
                'created_at' => $material->created_at ?: now(),
                'updated_at' => $material->updated_at ?: now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_attachments');
    }
};
