<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Material;
use App\Models\User;
use App\Models\ActivityLog;
use App\Models\Curriculum;
use App\Models\GradeLevel;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class DatabaseDrivenRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private function references(): array
    {
        return [
            Category::create(['name' => 'Verified category']),
        ];
    }

    public function test_database_notifications_can_be_read_and_deleted_only_by_their_owner(): void
    {
        $owner = User::factory()->create(['role' => 'contributor']);
        $other = User::factory()->create(['role' => 'contributor']);
        $id = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id' => $id,
            'type' => 'App\\Notifications\\MaterialUpdate',
            'notifiable_type' => User::class,
            'notifiable_id' => $owner->id,
            'data' => json_encode(['title' => 'Review update']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($other)->post(route('notifications.read', $id))->assertNotFound();
        $this->actingAs($owner)->post(route('notifications.read', $id))->assertRedirect();
        $this->assertDatabaseHas('notifications', ['id' => $id, 'notifiable_id' => $owner->id]);
        $this->assertNotNull(DB::table('notifications')->where('id', $id)->value('read_at'));

        $this->actingAs($other)->delete(route('notifications.destroy', $id))->assertNotFound();
        $this->actingAs($owner)->delete(route('notifications.destroy', $id))->assertRedirect();
        $this->assertDatabaseMissing('notifications', ['id' => $id]);
    }

    public function test_contributor_submissions_persist_and_are_scoped_by_owner(): void
    {
        $category = $this->references()[0];
        $contributor = User::factory()->create(['role' => 'contributor']);
        $other = User::factory()->create(['role' => 'contributor']);
        $payload = ['title' => 'Community guide', 'description' => 'Verified description', 'category_id' => $category->id, 'grade_levels' => [DB::table('grade_levels')->where('code','G2')->value('id')], 'subjects' => [DB::table('subjects')->where('name','Mathematics')->value('id')], 'intent' => 'draft'];

        $this->actingAs($contributor)->post(route('contributor.submissions.store'), $payload)->assertRedirect(route('contributor.submissions'));
        $material = Material::where('title', 'Community guide')->firstOrFail();
        $this->assertSame($contributor->id, $material->contributor_id);
        $material->update(['status' => 'Pending']);
        $this->assertSame('Pending', $material->status);
        $this->assertSame('Verified category', $material->category->name);
        $this->get(route('contributor.edit', $material))->assertForbidden();
        $this->put(route('contributor.update', $material), [...$payload, 'title' => 'Updated community guide'])->assertForbidden();
        $material->update(['status' => 'Revision Requested']);
        $this->get(route('contributor.edit', $material))->assertOk();
        $this->put(route('contributor.update', $material), [...$payload, 'title' => 'Updated community guide', 'intent' => 'submit'])->assertRedirect(route('contributor.submissions'));
        $this->actingAs($other)->get(route('contributor.edit', $material))->assertForbidden();
        $this->put(route('contributor.update', $material), $payload)->assertForbidden();
        $this->assertDatabaseHas('materials', ['id' => $material->id, 'title' => 'Updated community guide']);
    }

    public function test_public_repository_only_exposes_approved_records_and_filters_in_sql(): void
    {
        $category = $this->references()[0];
        $user = User::factory()->create();
        Material::create(['title' => 'Approved guide', 'description' => 'Public text', 'contributor_id' => $user->id, 'category_id' => $category->id, 'status' => 'Approved', 'published_at' => now()]);
        Material::create(['title' => 'Pending secret', 'contributor_id' => $user->id, 'status' => 'Pending']);

        $this->get(route('repository').'?q=Approved&category='.$category->id)
            ->assertOk()->assertSee('Approved guide')->assertDontSee('Pending secret')->assertSee('Verified category')->assertDontSee($user->email);
        $this->get('/materials/2')->assertNotFound();
    }

    public function test_repository_uses_server_pagination(): void
    {
        $category = $this->references()[0];
        $user = User::factory()->create();
        foreach (range(1, 11) as $i) Material::create(['title' => "Published guide {$i}", 'contributor_id' => $user->id, 'category_id' => $category->id, 'status' => 'Approved', 'published_at' => now()]);
        $this->get('/repository')->assertOk()->assertSee('"total":11', false)->assertSee('Published guide 11');
        $this->get('/repository?page=2')->assertOk()->assertSee('"current_page":2', false)->assertSee('Published guide 1');
    }

    public function test_repository_filter_state_survives_refresh_and_pagination_payload(): void
    {
        $category = $this->references()[0];
        $contributor = User::factory()->create();
        $subject = Subject::where('name', 'Mathematics')->firstOrFail();
        $grade = GradeLevel::where('code', 'G2')->firstOrFail();
        $material = Material::create(['title' => 'Filtered learning material', 'contributor_id' => $contributor->id, 'category_id' => $category->id, 'status' => 'Approved', 'published_at' => now()]);
        $material->subjects()->attach($subject);
        $material->gradeLevels()->attach($grade);
        $query = http_build_query(['q' => 'Filtered', 'subject' => $subject->id, 'grade' => $grade->id, 'category' => $category->id, 'sort' => 'title']);

        $this->get('/repository?'.$query)
            ->assertOk()
            ->assertSee('"filter_values":{"q":"Filtered","subject":"'.$subject->id.'","grade":"'.$grade->id.'","category":"'.$category->id.'","sort":"title"}', false)
            ->assertSee('"title":"Filtered learning material"', false);
    }

    public function test_public_repository_and_detail_pages_embed_real_database_records_for_renderer(): void
    {
        $category = $this->references()[0];
        $contributor = User::factory()->create(['name' => 'Real Contributor']);
        $material = Material::create(['title' => 'Database visible card', 'description' => 'A live database record', 'contributor_id' => $contributor->id, 'category_id' => $category->id, 'status' => 'Approved', 'published_at' => now()]);
        $this->get('/repository')->assertOk()->assertSee('Database visible card')->assertSee('Real Contributor')->assertSee('data-page="repository"', false);
        $this->get('/materials/'.$material->id)->assertOk()->assertSee('"kind":"material"', false)->assertSee('A live database record');
        $this->assertStringContainsString("backend.kind === \"repository\"", file_get_contents(resource_path('js/bayani-phase12.js')));
    }

    public function test_search_matches_related_education_and_contributor_metadata(): void
    {
        [$category] = $this->references();
        $contributor = User::factory()->create(['name' => 'Metadata Contributor', 'organization' => 'Learning Lab']);
        $curriculum = Curriculum::create(['name' => 'Custom Curriculum X', 'code' => 'CUSTOM-X', 'status' => 'active']);
        $subject = Subject::create(['name' => 'Integrated Science', 'code' => 'INT-SCI-X', 'status' => 'active']);
        $grade = GradeLevel::create(['name' => 'Grade 99', 'code' => 'G99-X', 'stage' => 'elementary', 'sequence' => 50]);
        $material = Material::create(['title' => 'Learning pack', 'description' => 'Teacher resource', 'topic' => 'ecosystems', 'keywords' => 'biodiversity', 'contributor_id' => $contributor->id, 'category_id' => $category->id, 'curriculum_id' => $curriculum->id, 'status' => 'Approved', 'published_at' => now()]);
        $material->subjects()->attach($subject);
        $material->gradeLevels()->attach($grade);

        foreach (['Integrated Science', 'Grade 99', 'Custom Curriculum', 'Learning Lab', 'ecosystems'] as $term) {
            $this->get('/repository?q='.urlencode($term))->assertOk()->assertSee('Learning pack');
        }
        $this->assertSame(1, Material::query()->where('status', 'Approved')->where('curriculum_id', $curriculum->id)->where('category_id', $category->id)->whereHas('subjects', fn ($query) => $query->where('subjects.id', $subject->id))->whereHas('gradeLevels', fn ($query) => $query->where('grade_levels.id', $grade->id))->count());
    }

    public function test_favorites_are_persistent_user_scoped_and_approved_only(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $approved = Material::create(['title' => 'Saved resource', 'contributor_id' => $user->id, 'status' => 'Approved', 'published_at' => now()]);
        $pending = Material::create(['title' => 'Private draft', 'contributor_id' => $user->id, 'status' => 'Pending']);

        $this->actingAs($user)->post(route('favorites.store', $approved))->assertRedirect();
        $this->assertDatabaseHas('favorites', ['user_id' => $user->id, 'material_id' => $approved->id]);
        $this->post(route('favorites.store', $pending))->assertNotFound();
        $this->get(route('favorites'))->assertOk()->assertSee('Saved resource')->assertDontSee('Private draft');
        $this->actingAs($other)->get(route('favorites'))->assertOk()->assertDontSee('Saved resource');
        $this->delete(route('favorites.destroy', $approved))->assertRedirect();
        $this->assertDatabaseMissing('favorites', ['user_id' => $other->id, 'material_id' => $approved->id]);
    }

    public function test_admin_can_manage_metadata_and_review_but_contributor_cannot(): void
    {
        $category = $this->references()[0];
        $user = User::factory()->create();
        $material = Material::create(['title' => 'Needs review', 'contributor_id' => $user->id, 'category_id' => $category->id, 'status' => 'Pending']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)->patch(route('admin.materials.status', $material), ['status' => 'Approved'])->assertForbidden();
        $this->actingAs($admin)->patch(route('admin.materials.status', $material), ['status' => 'Approved'])->assertRedirect(route('admin.queue'));
        $this->assertDatabaseHas('materials', ['id' => $material->id, 'status' => 'Approved']);
        $this->assertDatabaseHas('material_reviews', ['material_id' => $material->id, 'reviewer_id' => $admin->id, 'decision' => 'Approved']);
        $this->put(route('admin.materials.update', $material), ['title' => 'Edited by admin', 'description' => 'Admin update', 'category_id' => $category->id])->assertRedirect(route('admin.materials'));
        $this->assertDatabaseHas('materials', ['id' => $material->id, 'title' => 'Edited by admin', 'status' => 'Pending', 'published_at' => null]);
        $this->post('/admin/categories', ['name' => 'Additional category', 'description' => 'A managed taxonomy'])->assertRedirect();
        $created = Category::where('name', 'Additional category')->firstOrFail();
        $this->put('/admin/categories/'.$created->id, ['name' => 'Renamed category', 'description' => 'Updated description'])->assertRedirect();
        $this->assertDatabaseHas('categories', ['id' => $created->id, 'name' => 'Renamed category']);
        $this->delete('/admin/categories/'.$category->id)->assertStatus(409);
        $this->patch('/admin/categories/'.$category->id.'/status')->assertRedirect();
        $this->assertDatabaseHas('categories', ['id' => $category->id, 'status' => 'inactive']);
    }

    public function test_removed_metadata_routes_are_unavailable_and_not_rendered(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/admin/barangays')->assertNotFound();
        $this->get('/admin/eras')->assertNotFound();
        $this->get('/repository')->assertOk()->assertDontSee('Barangay')->assertDontSee('Historical era');
    }

    public function test_administrator_workspaces_do_not_render_retired_metadata(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        foreach (['/admin', '/admin/materials', '/admin/categories'] as $path) {
            $this->actingAs($admin)->get($path)->assertOk()->assertDontSee('Barangay')->assertDontSee('Historical era');
        }
    }

    public function test_category_duplicate_names_are_rejected(): void
    {
        Category::create(['name' => 'Existing category']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->from('/admin/categories')->post('/admin/categories', ['name' => 'Existing category'])->assertSessionHasErrors('name');
    }

    public function test_material_validation_failure_does_not_create_a_record(): void
    {
        $contributor = User::factory()->create(['role' => 'contributor']);
        $this->actingAs($contributor)->from('/contributor/upload')->post(route('contributor.submissions.store'), [
            'title' => '', 'description' => '', 'category_id' => 999, 'file' => UploadedFile::fake()->createWithContent('lesson.png', 'fixture'),
        ])->assertSessionHasErrors(['title', 'category_id']);
        $this->assertDatabaseCount('materials', 0);
    }

    public function test_submission_review_revision_resubmission_and_database_notifications_are_persisted(): void
    {
        $contributor = User::factory()->create(['role' => 'contributor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($contributor)->post(route('contributor.submissions.store'), ['title' => 'Workflow material', 'description' => 'Before review', 'intent' => 'draft'])->assertRedirect(route('contributor.submissions'));
        $material = Material::where('title', 'Workflow material')->firstOrFail();
        $this->assertSame('Draft', $material->status);

        $this->actingAs($contributor)->put(route('contributor.update', $material), ['title' => $material->title, 'description' => $material->description, 'grade_levels' => [DB::table('grade_levels')->where('code','G2')->value('id')], 'subjects' => [DB::table('subjects')->where('name','Mathematics')->value('id')], 'intent' => 'submit'])->assertRedirect(route('contributor.submissions'));
        $this->assertSame('Pending', $material->fresh()->status);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $admin->id]);
        $this->actingAs($admin)->patch(route('admin.materials.status', $material), ['status' => 'Revision Requested'])->assertSessionHasErrors('remarks');
        $this->patch(route('admin.materials.status', $material), ['status' => 'Revision Requested', 'remarks' => 'Add a source citation.'])->assertRedirect(route('admin.queue'));
        $this->assertDatabaseHas('material_reviews', ['material_id' => $material->id, 'decision' => 'Revision Requested', 'remarks' => 'Add a source citation.']);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $contributor->id]);
        $this->actingAs($contributor)->get(route('contributor.detail', $material))->assertOk()->assertSee('Add a source citation.');
        $this->put(route('contributor.update', $material), ['title' => 'Workflow material corrected', 'description' => 'After review', 'grade_levels' => [DB::table('grade_levels')->where('code','G2')->value('id')], 'subjects' => [DB::table('subjects')->where('name','Mathematics')->value('id')], 'intent' => 'submit'])->assertRedirect(route('contributor.submissions'));
        $this->assertSame('Pending', $material->fresh()->status);
        $this->actingAs($admin)->patch(route('admin.materials.status', $material), ['status' => 'Approved', 'remarks' => 'Approved for publication.'])->assertRedirect(route('admin.queue'));
        $this->assertSame('Approved', $material->fresh()->status);
        $this->assertNotNull($material->fresh()->published_at);
        $this->assertDatabaseHas('activity_logs', ['subject_id' => $material->id, 'action' => 'material.reviewed']);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $contributor->id]);
        $this->actingAs($contributor)->get('/notifications')->assertOk()->assertSee('Your material was approved and published.');
        $this->get('/repository')->assertSee('Workflow material corrected');
    }

    public function test_rejected_material_can_be_resubmitted_and_archived_material_is_soft_deleted_and_private(): void
    {
        $contributor = User::factory()->create(['role' => 'contributor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $material = Material::create(['title' => 'Private draft', 'description' => 'Secret', 'contributor_id' => $contributor->id, 'status' => 'Pending']);
        $this->actingAs($admin)->patch(route('admin.materials.status', $material), ['status' => 'Rejected', 'remarks' => 'Source cannot be verified.'])->assertRedirect(route('admin.queue'));
        $this->actingAs($contributor)->put(route('contributor.update', $material), ['title' => 'Private draft corrected', 'description' => 'Added a citation', 'grade_levels' => [DB::table('grade_levels')->where('code','G2')->value('id')], 'subjects' => [DB::table('subjects')->where('name','Mathematics')->value('id')], 'intent' => 'submit'])->assertRedirect(route('contributor.submissions'));
        $this->assertSame('Pending', $material->fresh()->status);
        $this->actingAs($contributor)->delete(route('contributor.destroy', $material))->assertRedirect();
        $this->assertSoftDeleted('materials', ['id' => $material->id, 'status' => 'Archived']);
        $this->assertDatabaseHas('activity_logs', ['subject_id' => $material->id, 'action' => 'material.archived']);
        $this->get('/materials/'.$material->id)->assertNotFound();
    }

    public function test_admin_changes_to_published_metadata_unpublish_and_require_new_review(): void
    {
        $contributor = User::factory()->create(['role' => 'contributor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $material = Material::create(['title' => 'Published before edit', 'description' => 'Original', 'contributor_id' => $contributor->id, 'status' => 'Approved', 'published_at' => now()]);
        $this->actingAs($admin)->put(route('admin.materials.update', $material), ['title' => $material->title, 'description' => 'Changed after approval'])->assertRedirect(route('admin.materials'));
        $this->assertDatabaseHas('materials', ['id' => $material->id, 'status' => 'Pending', 'published_at' => null]);
        $this->get('/repository')->assertDontSee('Published before edit');
        $this->assertDatabaseHas('activity_logs', ['subject_id' => $material->id, 'action' => 'material.metadata_updated']);
    }

    public function test_admin_review_queue_filters_by_subject_grade_curriculum_contributor_and_status(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $contributor = User::factory()->create(['role' => 'contributor']);
        $other = User::factory()->create(['role' => 'contributor']);
        $curriculum = Curriculum::create(['name' => 'Queue curriculum', 'code' => 'QUEUE-CURR']);
        $grade = GradeLevel::where('code', 'G4')->firstOrFail();
        $subject = Subject::create(['name' => 'Queue subject', 'code' => 'QUEUE-SUBJECT']);
        $match = Material::create(['title' => 'Matching queue item', 'description' => 'Target', 'contributor_id' => $contributor->id, 'curriculum_id' => $curriculum->id, 'status' => 'Pending']);
        $match->subjects()->attach($subject->id);
        $match->gradeLevels()->attach($grade->id);
        Material::create(['title' => 'Other contributor item', 'description' => 'Other', 'contributor_id' => $other->id, 'curriculum_id' => $curriculum->id, 'status' => 'Pending']);
        Material::create(['title' => 'Already published item', 'description' => 'Public', 'contributor_id' => $contributor->id, 'curriculum_id' => $curriculum->id, 'status' => 'Approved', 'published_at' => now()]);

        $url = route('admin.queue').'?status=Pending&subject='.$subject->id.'&grade='.$grade->id.'&curriculum='.$curriculum->id.'&contributor='.$contributor->id;
        $this->actingAs($admin)->get($url)->assertOk()->assertSee('Matching queue item')->assertDontSee('Other contributor item')->assertDontSee('Already published item');
    }
}
