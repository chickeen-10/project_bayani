<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Material;
use App\Models\MaterialReview;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_phase_three_tables_and_constraints_are_created(): void
    {
        foreach ([
            'users', 'categories', 'materials',
            'material_reviews', 'favorites', 'notifications', 'activity_logs', 'system_settings',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing {$table} table");
        }

        $this->assertTrue(Schema::hasColumn('materials', 'deleted_at'));
        $this->assertTrue(Schema::hasColumn('materials', 'contributor_id'));
        $this->assertTrue(Schema::hasColumn('notifications', 'notifiable_type'));
        $this->assertTrue(Schema::hasColumn('notifications', 'notifiable_id'));
    }

    public function test_material_relations_and_status_vocabulary_match_prototype(): void
    {
        $user = User::factory()->create();
        $category = Category::create(['name' => 'Social Studies']);
        $material = Material::create([
            'title' => 'Oral history guide',
            'contributor_id' => $user->id,
            'category_id' => $category->id,
            'status' => 'Pending',
        ]);
        $review = MaterialReview::create([
            'material_id' => $material->id,
            'reviewer_id' => $user->id,
            'decision' => 'Revision Requested',
        ]);
        $user->favorites()->attach($material->id);
        $log = ActivityLog::create(['user_id' => $user->id, 'action' => 'submitted']);
        SystemSetting::create(['key' => 'repository_name', 'value' => 'Project BAYANI']);

        $this->assertTrue($material->contributor->is($user));
        $this->assertTrue($material->category->is($category));
        $this->assertFalse(method_exists($material, 'barangay'));
        $this->assertFalse(method_exists($material, 'historicalEra'));
        $this->assertTrue($material->reviews->first()->is($review));
        $this->assertTrue($user->favorites->first()->is($material));
        $this->assertSame('submitted', $log->user->activityLogs->first()->action);
        $this->assertTrue($material->canTransitionTo('Approved'));
        $this->assertFalse($material->canTransitionTo('Draft'));
        $this->assertSame(['Draft', 'Pending', 'Approved', 'Revision Requested', 'Rejected', 'Archived'], Material::STATUSES);
    }

    public function test_unique_favorites_reject_duplicates_and_invalid_foreign_keys(): void
    {
        $user = User::factory()->create();
        $material = Material::create(['title' => 'Guide', 'contributor_id' => $user->id]);
        $user->favorites()->attach($material->id);

        try {
            $user->favorites()->attach($material->id);
            $this->fail('Duplicate user/material favorite should violate its unique constraint.');
        } catch (QueryException) {
            $this->assertSame(1, $user->favorites()->count());
        }

        $this->expectException(QueryException::class);
        Material::create(['title' => 'Orphan', 'contributor_id' => 999999]);
    }
}
