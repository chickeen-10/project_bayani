<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table): void {
            $table->string('file_disk', 40)->nullable()->after('file_path');
            $table->char('file_sha256', 64)->nullable()->after('file_size');
        });
    }

    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table): void {
            $table->dropColumn(['file_disk', 'file_sha256']);
        });
    }
};
