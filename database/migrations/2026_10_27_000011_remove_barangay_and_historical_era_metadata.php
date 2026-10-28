<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The migration is intentionally forward-only in practice. It guards against
     * silently discarding any metadata added after the verified backup snapshot.
     */
    public function up(): void
    {
        foreach (['barangays', 'historical_eras'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("Refusing to remove {$table}: records exist; export and review them first.");
            }
        }

        foreach (['barangay_id', 'historical_era_id'] as $column) {
            if (Schema::hasColumn('materials', $column) && DB::table('materials')->whereNotNull($column)->exists()) {
                throw new RuntimeException("Refusing to remove materials.{$column}: referenced materials exist.");
            }
        }

        if (Schema::hasColumn('materials', 'barangay_id')) {
            Schema::table('materials', function (Blueprint $table): void {
                $table->dropForeign(['barangay_id']);
                $table->dropColumn('barangay_id');
            });
        }
        if (Schema::hasColumn('materials', 'historical_era_id')) {
            Schema::table('materials', function (Blueprint $table): void {
                $table->dropForeign(['historical_era_id']);
                $table->dropColumn('historical_era_id');
            });
        }

        Schema::dropIfExists('barangays');
        Schema::dropIfExists('historical_eras');
    }

    public function down(): void
    {
        Schema::create('barangays', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });
        Schema::create('historical_eras', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->integer('start_year')->nullable();
            $table->integer('end_year')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });
        Schema::table('materials', function (Blueprint $table): void {
            $table->foreignId('barangay_id')->nullable()->constrained('barangays');
            $table->foreignId('historical_era_id')->nullable()->constrained('historical_eras');
        });
    }
};
