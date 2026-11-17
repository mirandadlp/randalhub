<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_requires_authentication(): void
    {
        foreach (['/api/jobs', '/api/dashboard', '/api/profile', '/api/settings', '/api/system', '/api/applications'] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
    }

    public function test_first_run_registration_then_closed(): void
    {
        $this->getJson('/auth/status')->assertJson(['needs_setup' => true, 'authenticated' => false]);

        $this->postJson('/auth/register', ['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'a-long-password-1', 'password_confirmation' => 'a-long-password-1'])
            ->assertCreated();
        $this->assertAuthenticated();

        auth()->logout();
        $this->postJson('/auth/register', ['name' => 'Intruder', 'email' => 'x@example.test', 'password' => 'a-long-password-2', 'password_confirmation' => 'a-long-password-2'])
            ->assertForbidden();
        $this->assertSame(1, User::count());
    }

    public function test_registration_requires_strong_password(): void
    {
        $this->postJson('/auth/register', ['name' => 'O', 'email' => 'o@example.test', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_login_and_logout(): void
    {
        User::factory()->create(['email' => 'owner@example.test', 'password' => 'a-long-password-1']);

        $this->postJson('/auth/login', ['email' => 'owner@example.test', 'password' => 'wrong-password'])->assertStatus(422);
        $this->postJson('/auth/login', ['email' => 'owner@example.test', 'password' => 'a-long-password-1'])->assertOk();
        $this->assertAuthenticated();
        $this->getJson('/api/me')->assertJson(['email' => 'owner@example.test']);

        $this->postJson('/auth/logout')->assertOk();
        $this->assertGuest('web');
    }

    public function test_login_is_rate_limited(): void
    {
        User::factory()->create(['email' => 'owner@example.test']);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/auth/login', ['email' => 'owner@example.test', 'password' => 'wrong'])->assertStatus(422);
        }
        $this->postJson('/auth/login', ['email' => 'owner@example.test', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_spa_shell_is_served(): void
    {
        $this->withoutVite();
        $this->get('/jobs/123')->assertOk()->assertSee('id="app"', false);
    }

    public function test_create_user_command(): void
    {
        $this->artisan('jobhunter:create-user', ['email' => 'me@example.test', '--password' => 'another-long-password'])->assertSuccessful();
        $this->artisan('jobhunter:create-user', ['email' => 'other@example.test', '--password' => 'another-long-password'])->assertFailed();
    }
}
