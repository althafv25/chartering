<?php

namespace Tests\Feature\Security;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ApiHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_responses_carry_hardening_headers_including_errors(): void
    {
        $this->seedCore();
        Sanctum::actingAs($this->userWithRole(UserRole::Finance));

        foreach ([$this->getJson('/api/v1/dashboard'), $this->getJson('/api/v1/users'), $this->getJson('/api/v1/nope')] as $response) {
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
            $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
            $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            $this->assertNotEmpty($response->headers->get('X-Request-Id'));
        }
    }

    public function test_unauthenticated_responses_are_also_hardened(): void
    {
        $response = $this->getJson('/api/v1/dashboard')->assertUnauthorized();

        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_server_errors_never_expose_exception_details_unless_debug_is_on(): void
    {
        $this->seedCore();
        // An authenticated route that fails server-side: an unknown permission name makes Spatie throw.
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        DB::table('permissions')->where('name', 'users.view')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        config(['app.debug' => false]);
        $prod = $this->getJson('/api/v1/users')->assertStatus(500);
        $prod->assertJsonMissingPath('debug');
        $this->assertStringNotContainsString('PermissionDoesNotExist', (string) $prod->getContent());
        $this->assertNotEmpty($prod->json('request_id'));

        config(['app.debug' => true]);
        $this->getJson('/api/v1/users')->assertStatus(500)->assertJsonPath('debug.exception', PermissionDoesNotExist::class);
    }

    public function test_cors_only_allows_the_configured_frontend_origin(): void
    {
        $ok = $this->withHeaders(['Origin' => config('offshore.frontend_url')])->getJson('/api/v1/dashboard');
        $this->assertSame(config('offshore.frontend_url'), $ok->headers->get('Access-Control-Allow-Origin'));

        // A foreign origin never gets "*" or its own origin echoed back; the browser rejects the mismatch with the allowed origin.
        $foreign = $this->withHeaders(['Origin' => 'https://evil.example'])->getJson('/api/v1/dashboard');
        $this->assertNotContains($foreign->headers->get('Access-Control-Allow-Origin'), ['*', 'https://evil.example']);

        $preflight = $this->withHeaders(['Origin' => 'https://evil.example', 'Access-Control-Request-Method' => 'POST'])->call('OPTIONS', '/api/v1/invoices');
        $this->assertNotContains($preflight->headers->get('Access-Control-Allow-Origin'), ['*', 'https://evil.example']);
    }

    public function test_login_is_throttled_per_account_and_ip(): void
    {
        $this->seedCore();
        User::factory()->create(['email' => 'target@example.test']);

        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $codes[] = $this->postJson('/api/v1/auth/login', ['email' => 'target@example.test', 'password' => 'wrong-password'])->getStatusCode();
        }

        $this->assertContains(429, $codes);
        $this->assertNotContains(200, $codes);
        $this->assertSame(429, end($codes));
    }
}
