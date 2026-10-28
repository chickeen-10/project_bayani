<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Material;
use App\Models\MaterialAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MaterialArchiveManagementTest extends TestCase
{
    use RefreshDatabase;

    private function storedMaterial(User $owner, string $title, string $status = 'Approved', string $path = 'materials/archive-test.pdf'): Material
    {
        Storage::disk('bayani_uploads')->put($path, 'isolated test material');
        Storage::disk('bayani_uploads')->put('thumbnails/archive-test.png', 'isolated thumbnail');
        $material = Material::create([
            'title' => $title,
            'description' => 'Isolated archive fixture',
            'contributor_id' => $owner->id,
            'status' => $status,
            'published_at' => $status === 'Approved' ? now()->subDay() : null,
            'file_path' => $path,
            'file_disk' => 'bayani_uploads',
            'original_filename' => 'archive-test.pdf',
            'file_type' => 'application/pdf',
            'file_size' => 22,
            'thumbnail_path' => 'thumbnails/archive-test.png',
        ]);
        MaterialAttachment::create([
            'material_id' => $material->id,
            'original_filename' => 'archive-test.pdf',
            'stored_path' => $path,
            'storage_disk' => 'bayani_uploads',
            'mime_type' => 'application/pdf',
            'file_size' => 22,
            'display_order' => 0,
            'is_primary' => true,
            'primary_material_id' => $material->id,
            'thumbnail_path' => 'thumbnails/archive-test.png',
        ]);

        return $material;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('bayani_uploads');
    }

    public function test_guest_navigation_stays_public_and_workspace_routes_require_authentication(): void
    {
        $this->get(route('repository'))->assertOk()->assertSee('Sign in')->assertSee('Join as a contributor');
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->get(route('contributor.dashboard'))->assertRedirect(route('login'));
    }

    public function test_shared_navigation_exposes_role_workspace_and_upload_only_to_contributors(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $contributor = User::factory()->create(['role' => 'contributor']);

        $adminPage = $this->actingAs($admin)->get(route('admin.dashboard'));
        $adminPage->assertOk()
            ->assertSee('href="'.route('admin.dashboard').'"', false)
            ->assertSee('Dashboard')->assertSee('Notifications')->assertSee('Favorites')
            ->assertDontSee('Upload material')->assertSee('About')->assertSee('Help');
        $this->assertSame(1, substr_count($adminPage->getContent(), 'href="'.route('admin.dashboard').'"'), 'The server-rendered header retains one role-aware dashboard link.');
        $this->assertSame(1, substr_count($adminPage->getContent(), 'href="'.route('profile').'"'));
        $this->assertSame(1, substr_count($adminPage->getContent(), 'href="'.route('account.settings').'"'));
        $contributorPage = $this->actingAs($contributor)->get(route('contributor.dashboard'));
        $contributorPage->assertOk()
            ->assertSee('href="'.route('contributor.dashboard').'"', false)
            ->assertSee('href="'.route('contributor.upload').'"', false)
            ->assertSee('Upload Material')->assertSee('Favorites');
        $this->assertSame(1, substr_count($contributorPage->getContent(), 'href="'.route('contributor.dashboard').'"'), 'The server-rendered header retains one role-aware dashboard link.');
        $this->actingAs($contributor)->get(route('admin.dashboard'))->assertForbidden();
        $this->actingAs($admin)->get(route('contributor.dashboard'))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.archived'))->assertOk();
        $this->actingAs($contributor)->get(route('contributor.archived'))->assertOk();
    }

    public function test_archive_listing_is_scoped_and_restore_preserves_approved_visibility_and_attachments(): void
    {
        $owner = User::factory()->create(['role' => 'contributor']);
        $other = User::factory()->create(['role' => 'contributor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $material = $this->storedMaterial($owner, 'Isolated archived item');
        $otherMaterial = $this->storedMaterial($other, 'Other contributor archive', 'Draft', 'materials/other-archive-test.pdf');
        $otherMaterial->delete();
        $otherMaterial->status = 'Archived';
        $otherMaterial->save();
        $publishedAt = $material->fresh()->published_at->toISOString();

        $this->actingAs($owner)->delete(route('contributor.destroy', $material))->assertRedirect();
        $this->assertSoftDeleted('materials', ['id' => $material->id, 'status' => 'Archived']);
        Storage::disk('bayani_uploads')->assertExists('materials/archive-test.pdf');
        $this->actingAs($owner)->get(route('contributor.archived'))->assertOk()
            ->assertSee('Isolated archived item')->assertDontSee('Other contributor archive');
        $this->actingAs($admin)->get(route('admin.archived'))->assertOk()
            ->assertSee('Isolated archived item')->assertSee('Other contributor archive');
        $this->actingAs($admin)->get(route('admin.archived.detail', $material->id))->assertOk()
            ->assertSee('data-page="archived-detail"', false)->assertSee('archive-test.pdf')
            ->assertSee('window.BayaniBackend', false)->assertDontSee('/materials/'.$material->id.'/preview')
            ->assertDontSee('"stored_path"', false)->assertDontSee('"file_path"', false);
        $this->actingAs($other)->get(route('contributor.archived.detail', $material->id))->assertForbidden();
        $this->actingAs($owner)->get(route('contributor.archived.detail', $material->id))->assertOk();
        $this->get(route('material', $material))->assertNotFound();
        $this->get(route('materials.attachments.preview', [$material->id, $material->attachments()->first()->id]))->assertNotFound();

        $this->actingAs($admin)->patch(route('admin.archived.restore', $material->id))->assertRedirect();
        $restored = $material->fresh();
        $this->assertNull($restored->deleted_at);
        $this->assertSame('Approved', $restored->status);
        $this->assertSame($publishedAt, $restored->published_at->toISOString());
        $this->assertCount(1, $restored->attachments);
        Storage::disk('bayani_uploads')->assertExists('materials/archive-test.pdf');
        Storage::disk('bayani_uploads')->assertExists('thumbnails/archive-test.png');
        $this->get(route('material', $material))->assertOk();
        $this->assertDatabaseHas('activity_logs', ['subject_id' => $material->id, 'action' => 'material.restored']);
        $this->assertNotNull($otherMaterial->fresh()->deleted_at);
    }

    public function test_database_notification_badge_persists_and_read_delete_actions_update_it(): void
    {
        $user = User::factory()->create(['role' => 'contributor']);
        $other = User::factory()->create(['role' => 'contributor']);
        $user->notify(new \App\Notifications\MaterialWorkflowNotification('approved', 9001, 'Notice one'));
        $user->notify(new \App\Notifications\MaterialWorkflowNotification('rejected', 9002, 'Notice two'));
        $other->notify(new \App\Notifications\MaterialWorkflowNotification('approved', 9003, 'Other account notice'));
        $notifications = $user->notifications()->latest()->get();
        $first = $notifications->first();
        $second = $notifications->last();

        $this->actingAs($user)->get(route('contributor.dashboard'))->assertOk()
            ->assertSee('data-unread-count="2"', false)->assertSee('Notifications, 2 unread');
        $this->get(route('repository'))->assertOk()->assertSee('data-unread-count="2"', false);
        $this->post(route('notifications.read', $first->id))->assertRedirect();
        $this->get(route('home'))->assertOk()->assertSee('data-unread-count="1"', false);
        $this->post(route('notifications.read-all'))->assertRedirect();
        $this->get(route('contributor.dashboard'))->assertOk()->assertSee('data-unread-count="0"', false)
            ->assertSee('class="notification-count d-none"', false);
        $this->delete(route('notifications.destroy', $second->id))->assertRedirect();
        $this->assertDatabaseMissing('notifications', ['id' => $second->id]);
        $this->get(route('repository'))->assertOk()->assertSee('data-unread-count="0"', false);
        $this->actingAs($other)->get(route('repository'))->assertOk()->assertSee('data-unread-count="1"', false)
            ->assertDontSee('Notice one');
    }

    public function test_permanent_delete_is_admin_only_archived_only_cascades_records_and_safely_cleans_unshared_files(): void
    {
        $owner = User::factory()->create(['role' => 'contributor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $reviewer = User::factory()->create(['role' => 'admin']);
        $material = $this->storedMaterial($owner, 'Isolated permanent-delete fixture');
        $material->reviews()->create(['reviewer_id' => $reviewer->id, 'decision' => 'Approved', 'remarks' => 'Test only', 'reviewed_at' => now()]);
        $material->favoritedBy()->attach($owner->id);

        $this->actingAs($admin)->delete(route('admin.archived.permanent-delete', $material->id), ['confirm_permanent_delete' => 'yes'])->assertNotFound();
        $this->assertDatabaseHas('materials', ['id' => $material->id]);
        $this->actingAs($admin)->delete(route('admin.materials.destroy', $material))->assertRedirect();
        $this->actingAs($owner)->delete(route('admin.archived.permanent-delete', $material->id), ['confirm_permanent_delete' => 'yes'])->assertForbidden();
        $this->actingAs($admin)->delete(route('admin.archived.permanent-delete', $material->id), ['confirm_permanent_delete' => 'yes'])->assertRedirect(route('admin.archived'));

        $this->assertDatabaseMissing('materials', ['id' => $material->id]);
        $this->assertDatabaseMissing('material_attachments', ['material_id' => $material->id]);
        $this->assertDatabaseMissing('material_reviews', ['material_id' => $material->id]);
        $this->assertDatabaseMissing('favorites', ['material_id' => $material->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'material.permanently_deleted', 'subject_id' => null]);
        Storage::disk('bayani_uploads')->assertMissing('materials/archive-test.pdf');
        Storage::disk('bayani_uploads')->assertMissing('thumbnails/archive-test.png');
    }

    public function test_permanent_delete_keeps_shared_files_and_never_deletes_unsafe_paths(): void
    {
        $owner = User::factory()->create(['role' => 'contributor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $archived = $this->storedMaterial($owner, 'Archived shared-file fixture');
        $active = Material::create(['title' => 'Active shared-file reference', 'contributor_id' => $owner->id, 'status' => 'Draft', 'file_path' => 'materials/archive-test.pdf', 'file_disk' => 'bayani_uploads']);
        MaterialAttachment::create(['material_id' => $active->id, 'original_filename' => 'shared.pdf', 'stored_path' => 'materials/archive-test.pdf', 'storage_disk' => 'bayani_uploads', 'file_size' => 22]);
        $archived->update(['file_path' => 'materials/../outside.pdf']);
        DB::table('material_attachments')->where('material_id', $archived->id)->update(['stored_path' => 'materials/../outside.pdf']);
        Storage::disk('bayani_uploads')->put('materials/outside.pdf', 'must remain');

        $this->actingAs($admin)->delete(route('admin.materials.destroy', $archived))->assertRedirect();
        $this->actingAs($admin)->delete(route('admin.archived.permanent-delete', $archived->id), ['confirm_permanent_delete' => 'yes'])->assertRedirect();

        Storage::disk('bayani_uploads')->assertExists('materials/archive-test.pdf');
        Storage::disk('bayani_uploads')->assertExists('materials/outside.pdf');
        $this->assertDatabaseHas('materials', ['id' => $active->id]);
        $audit = ActivityLog::where('action', 'material.permanently_deleted')->latest('id')->firstOrFail();
        $this->assertSame(1, $audit->details['cleanup_deferred']);
    }
}
