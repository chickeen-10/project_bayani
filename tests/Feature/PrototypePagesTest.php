<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Material;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class PrototypePagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_prototype_pages_load(): void
    {
        foreach ([
            '/', '/repository', '/search?q=market', '/about', '/login', '/register', '/help', '/help/feedback',
        ] as $uri) {
            $this->get($uri)
                ->assertOk()
                ->assertSee('id="app-content"', false)
                ->assertSee('type="module" src=', false)
                ->assertSee('data-bs-theme', false)
                ->assertDontSee('Informational prototype page');
        }
        $this->get('/materials/BY-001')->assertNotFound();
        $this->get('/materials/BY-001/preview')->assertNotFound();
        $this->get('/demo/login')->assertRedirect('/login');
    }

    public function test_material_routes_use_database_ids_while_old_demo_ids_are_not_exposed(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $contributor = User::factory()->create(['role' => 'contributor']);
        $material = Material::create(['title' => 'Real record', 'contributor_id' => $contributor->id, 'category_id' => Category::create(['name' => 'Category'])->id, 'status' => 'Pending']);
        $this->actingAs($admin)->get('/admin/review/'.$material->id)->assertOk()->assertSee('Real record');
        $this->actingAs($contributor)->get('/contributor/submissions/'.$material->id.'/edit')->assertForbidden();
        $this->get('/contributor/submissions/'.$material->id)->assertOk()->assertSee('Real record');
        $this->get('/admin/review/BY-010')->assertNotFound();
        Auth::logout();
        $this->get('/contributor/submissions/'.$material->id)->assertRedirect('/login');
        $this->actingAs($admin)->get('/admin/users/U-001')->assertOk()->assertSee('data-item-id="U-001"', false);
    }

    public function test_every_prototype_route_includes_the_shared_prepaint_theme_manager(): void
    {
        foreach ([
            '/', '/repository', '/about', '/help', '/login', '/register',
        ] as $uri) {
            $this->get($uri)
                ->assertOk()
                ->assertSee("const themeKey = 'bayani.theme'", false)
                ->assertSee('data-bs-theme', false)
                ->assertDontSee('Informational prototype page');
        }
    }

    public function test_private_workspace_and_account_pages_require_authentication(): void
    {
        foreach (['/demo/profile', '/demo/settings', '/notifications', '/favorites', '/recently-viewed', '/download-history', '/contributor', '/contributor/upload', '/admin', '/admin/review'] as $uri) {
            $this->get($uri)->assertRedirect('/login');
        }
    }

    public function test_sample_pdf_is_served_as_a_pdf(): void
    {
        $this->get('/sample-files/sample-history.pdf')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }
}
