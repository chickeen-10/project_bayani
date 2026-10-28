<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        // These are Project BAYANI organizational categories, not an official
        // DepEd taxonomy. Seed only a small set of broad groupings and keep the
        // more precise resource classification in material_types.
        $now = now();
        foreach ([
            'Teaching and Planning',
            'Learner Activities',
            'Assessment',
            'Reference and Enrichment',
        ] as $name) {
            DB::table('categories')->insertOrIgnore([
                'name' => $name,
                'description' => 'Project BAYANI organizational grouping; use Material Type for the specific resource format.',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
