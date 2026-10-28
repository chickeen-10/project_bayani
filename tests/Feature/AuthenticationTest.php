<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_a_hashed_contributor_account_even_if_admin_role_is_posted(): void
    {
        $response = $this->post('/register', [
            'name' => 'Mara Contributor',
            'email' => 'mara@example.test',
            'password' => 'secure-password-1',
            'password_confirmation' => 'secure-password-1',
            'role' => 'admin',
        ]);

        $response->assertRedirect(route('contributor.dashboard'));
        $user = User::where('email', 'mara@example.test')->firstOrFail();
        $this->assertSame('contributor', $user->role);
        $this->assertSame('active', $user->status);
        $this->assertTrue(Hash::check('secure-password-1', $user->password));
        $this->assertAuthenticatedAs($user);
    }

    public function test_registration_rejects_duplicate_email_and_invalid_confirmation(): void
    {
        User::factory()->create(['email' => 'existing@example.test']);

        $this->from('/register')->post('/register', [
            'name' => 'Duplicate',
            'email' => 'existing@example.test',
            'password' => 'secure-password-1',
            'password_confirmation' => 'different-password',
        ])->assertRedirect('/register')->assertSessionHasErrors(['email', 'password']);

        $this->assertSame(1, User::where('email', 'existing@example.test')->count());
    }

    public function test_login_authenticates_active_user_and_keeps_session_across_requests(): void
    {
        $user = User::factory()->create(['email' => 'member@example.test', 'password' => 'secure-password-1']);
        $this->withSession(['existing-session-value' => 'kept']);
        $oldSessionId = $this->app['session']->getId();

        $this->post('/login', [
            'email' => 'member@example.test',
            'password' => 'secure-password-1',
        ])->assertRedirect(route('contributor.dashboard'));

        $this->assertNotSame($oldSessionId, $this->app['session']->getId(), 'login regenerates the session identifier');
        $this->assertSame('kept', session('existing-session-value'));
        $this->assertAuthenticatedAs($user);
        $this->get('/contributor')->assertOk()->assertSee('data-page="contributor-dashboard"', false);
        $this->get('/demo/profile')->assertOk()->assertSee('member@example.test');
    }

    public function test_login_rejects_bad_credentials_and_inactive_users(): void
    {
        $user = User::factory()->create(['email' => 'inactive@example.test', 'password' => 'secure-password-1', 'status' => 'disabled']);

        $this->from('/login')->post('/login', [
            'email' => 'inactive@example.test',
            'password' => 'secure-password-1',
        ])->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');
    }

    public function test_login_endpoint_is_rate_limited(): void
    {
        RateLimiter::clear('abuse@example.test|127.0.0.1');
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->from('/login')->post('/login', ['email' => 'abuse@example.test', 'password' => 'incorrect-password'])
                ->assertRedirect('/login');
        }

        $this->post('/login', ['email' => 'abuse@example.test', 'password' => 'incorrect-password'])
            ->assertTooManyRequests();
    }

    public function test_logout_clears_authentication_and_private_routes_redirect_to_login(): void
    {
        $this->actingAs(User::factory()->create());
        $oldToken = session()->token();
        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();
        $this->assertNotSame($oldToken, session()->token(), 'logout rotates the CSRF token');
        $this->get('/contributor')->assertRedirect('/login');
    }

    public function test_contributors_and_administrators_are_limited_to_their_own_workspaces(): void
    {
        $contributor = User::factory()->create(['role' => 'contributor']);
        $this->actingAs($contributor)->get('/contributor')->assertOk();
        $this->get('/admin')->assertForbidden();

        Auth::logout();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/admin')->assertOk();
        $this->get('/contributor')->assertForbidden();
    }

    public function test_profile_updates_only_the_authenticated_users_record(): void
    {
        $owner = User::factory()->create(['name' => 'Original Name']);
        $other = User::factory()->create(['name' => 'Other Account']);

        $this->from('/demo/profile')->actingAs($owner)->put('/profile', [
            'name' => 'Updated Name',
            'email' => $owner->email,
            'organization' => 'Community School',
            'contact' => '555-0112',
            'bio' => 'Local history educator.',
            'user_id' => $other->id,
            'role' => 'admin',
        ])->assertRedirect('/demo/profile');

        $this->assertSame('Updated Name', $owner->fresh()->name);
        $this->assertSame('Community School', $owner->fresh()->organization);
        $this->assertSame('contributor', $owner->fresh()->role);
        $this->assertSame('Other Account', $other->fresh()->name);
    }

    public function test_administrator_role_is_assigned_only_after_cli_operator_confirmation(): void
    {
        $user = User::factory()->create(['email' => 'candidate@example.test', 'role' => 'contributor']);

        $this->artisan('bayani:grant-admin', ['email' => $user->email])
            ->expectsConfirmation("Grant administrator access to {$user->email}?", 'no')
            ->assertExitCode(0);
        $this->assertSame('contributor', $user->fresh()->role);

        $this->artisan('bayani:grant-admin', ['email' => $user->email])
            ->expectsConfirmation("Grant administrator access to {$user->email}?", 'yes')
            ->expectsOutput('Administrator access granted.')
            ->assertExitCode(0);
        $this->assertSame('admin', $user->fresh()->role);
    }

    public function test_public_repository_is_available_to_guests_and_authenticated_theme_bootstrap_remains_shared(): void
    {
        $this->get('/repository')->assertOk();
        $user = User::factory()->create(['role' => 'contributor']);
        $this->actingAs($user)->get('/contributor')
            ->assertOk()
            ->assertSee("const themeKey = 'bayani.theme'", false)
            ->assertSee('window.BayaniAuth', false);
    }

    public function test_profile_accepts_supported_images_and_stores_optimized_private_derivatives(): void
    {
        Storage::fake('public');
        Storage::fake('bayani_uploads');
        $user = User::factory()->create(['role' => 'contributor']);
        foreach (['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'] as $extension => $mime) {
            $file = UploadedFile::fake()->image("profile.{$extension}")->size(100);
            $this->actingAs($user)->put('/profile', ['name' => $user->name, 'email' => $user->email, 'profile_image' => $file])->assertRedirect();
            $path = $user->fresh()->profile_image;
            $this->assertStringStartsWith('profile-images/', $path);
            Storage::disk('bayani_uploads')->assertExists($path);
            $this->assertSame($mime, Storage::disk('bayani_uploads')->mimeType($path));
            $this->get(route('profile.avatar'))->assertOk()->assertHeader('Content-Type', $mime)->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cache-Control', 'immutable, max-age=31536000, private');
            $this->get('/contributor')->assertOk()->assertSee('/profile/avatar?v=', false)->assertDontSee('storage/profile-images/', false);
        }
    }

    public function test_profile_avatar_requires_authentication_and_only_serves_current_users_image(): void
    {
        Storage::fake('bayani_uploads');
        $owner = User::factory()->create(['profile_image' => 'profile-images/owner.png']);
        $other = User::factory()->create(['profile_image' => 'profile-images/other.png']);
        Storage::disk('bayani_uploads')->put($owner->profile_image, 'owner-avatar-bytes');
        Storage::disk('bayani_uploads')->put($other->profile_image, 'other-avatar-bytes');

        $this->get(route('profile.avatar'))->assertRedirect('/login');
        $ownerResponse = $this->actingAs($owner)->get(route('profile.avatar'));
        $ownerResponse->assertOk();
        $this->assertSame('owner-avatar-bytes', $ownerResponse->streamedContent());
        $otherResponse = $this->actingAs($other)->get(route('profile.avatar'));
        $otherResponse->assertOk();
        $this->assertSame('other-avatar-bytes', $otherResponse->streamedContent());
    }

    public function test_legacy_public_profile_image_is_copied_to_private_storage_without_deleting_original(): void
    {
        Storage::fake('public');
        Storage::fake('bayani_uploads');
        $user = User::factory()->create(['role' => 'contributor', 'profile_image' => 'profile-images/legacy.png']);
        Storage::disk('public')->put($user->profile_image, UploadedFile::fake()->image('legacy.png')->getContent());

        $this->actingAs($user)->get(route('profile.avatar'))->assertOk();
        Storage::disk('bayani_uploads')->assertExists($user->profile_image);
        Storage::disk('public')->assertExists($user->profile_image);
    }

    public function test_profile_rejects_oversized_and_unsupported_images_without_replacing_existing_file(): void
    {
        Storage::fake('public');
        $user = User::factory()->create(['profile_image' => 'profile-images/existing.jpg']);
        Storage::disk('public')->put('profile-images/existing.jpg', 'preserve');

        $this->actingAs($user)->from('/demo/profile')->put('/profile', [
            'name' => $user->name, 'email' => $user->email,
            'profile_image' => UploadedFile::fake()->image('too-large.png')->size(10241),
        ])->assertRedirect('/demo/profile')->assertSessionHasErrors('profile_image');
        $this->assertSame('Profile picture must not exceed 10 MB.', session('errors')->first('profile_image'));
        $this->assertSame('profile-images/existing.jpg', $user->fresh()->profile_image);
        Storage::disk('public')->assertExists('profile-images/existing.jpg');

        $this->from('/demo/profile')->put('/profile', [
            'name' => $user->name, 'email' => $user->email,
            'profile_image' => UploadedFile::fake()->create('script.svg', 5, 'image/svg+xml'),
        ])->assertRedirect('/demo/profile')->assertSessionHasErrors('profile_image');
    }
}
