<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        RateLimiter::clear('login');
    }

    public function test_user_can_login_and_receives_token_and_permissions(): void
    {
        $this->userWithRole(UserRole::Management, ['email' => 'mgr@example.com']);

        $this->postJson('/api/v1/auth/login', ['email' => 'MGR@example.com', 'password' => 'Password123'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['token', 'expires_at', 'user' => ['id', 'email', 'roles', 'permissions', 'is_super_admin']]])
            ->assertJsonPath('data.user.roles.0', 'management')
            ->assertJsonPath('data.user.is_super_admin', false);

        $this->assertDatabaseHas('activity_log', ['log_name' => 'auth', 'event' => 'login']);
    }

    public function test_wrong_password_returns_generic_validation_error(): void
    {
        User::factory()->create(['email' => 'a@example.com']);

        $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'wrong'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'validation_failed')
            ->assertJsonPath('errors.email.0', 'These credentials do not match our records.');
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::factory()->inactive()->create(['email' => 'off@example.com']);

        $this->postJson('/api/v1/auth/login', ['email' => 'off@example.com', 'password' => 'Password123'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Your account is inactive. Please contact an administrator.');
    }

    public function test_login_is_rate_limited(): void
    {
        User::factory()->create(['email' => 'rl@example.com']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'rl@example.com', 'password' => 'bad'])->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'rl@example.com', 'password' => 'bad'])
            ->assertStatus(429)
            ->assertJsonPath('error_code', 'too_many_requests');
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('error_code', 'unauthenticated')
            ->assertHeader('X-Request-Id');
    }

    public function test_super_admin_me_lists_all_permissions(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        $this->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('data.is_super_admin', true)
            ->assertJsonFragment(['audit-logs.view']);
    }

    public function test_logout_revokes_current_token(): void
    {
        User::factory()->create(['email' => 'lo@example.com']);
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'lo@example.com', 'password' => 'Password123'])->json('data.token');

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_change_password_requires_current_password_and_revokes_other_tokens(): void
    {
        $user = User::factory()->create(['email' => 'cp@example.com']);
        $user->createToken('other-device');
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'cp@example.com', 'password' => 'Password123'])->json('data.token');

        $this->withToken($token)->postJson('/api/v1/auth/change-password', [
            'current_password' => 'nope', 'password' => 'NewPassword123', 'password_confirmation' => 'NewPassword123',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->withToken($token)->postJson('/api/v1/auth/change-password', [
            'current_password' => 'Password123', 'password' => 'NewPassword123', 'password_confirmation' => 'NewPassword123',
        ])->assertOk();

        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_forgot_password_does_not_reveal_unknown_email(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'fp@example.com']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'unknown@example.com'])->assertOk();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'fp@example.com'])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_password_with_valid_token(): void
    {
        $user = User::factory()->create(['email' => 'rp@example.com']);
        $user->createToken('x');
        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'rp@example.com', 'token' => $token,
            'password' => 'BrandNew123', 'password_confirmation' => 'BrandNew123',
        ])->assertOk();

        $this->assertSame(0, $user->tokens()->count());
        $this->postJson('/api/v1/auth/login', ['email' => 'rp@example.com', 'password' => 'BrandNew123'])->assertOk();
    }

    public function test_profile_update(): void
    {
        $user = $this->actingAsRole(UserRole::ReadOnly);

        $this->putJson('/api/v1/profile', ['first_name' => 'Jane', 'last_name' => 'Doe', 'timezone' => 'UTC'])
            ->assertOk()->assertJsonPath('data.name', 'Jane Doe');

        $this->assertSame('UTC', $user->fresh()->timezone);
    }

    public function test_expired_token_is_rejected(): void
    {
        $user = User::factory()->create();
        $plain = $user->createToken('web', ['*'], now()->subMinute())->plainTextToken;

        $this->withToken($plain)->getJson('/api/v1/auth/me')->assertStatus(401);
        Sanctum::actingAs($user); // keep static analysers quiet about unused import
    }
}
