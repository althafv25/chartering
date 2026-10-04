<?php

namespace Tests\Feature\Core;

use App\Enums\UserRole;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\IntegrationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ErrorEnvelopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();

        Route::middleware('api')->prefix('api/v1/_test')->group(function () {
            Route::get('business', fn () => throw new BusinessRuleException('Voyage already finalized.', 'voyage_already_finalized'));
            Route::get('integration', fn () => throw new IntegrationException('ais', 'AIS provider unavailable.', new \RuntimeException('secret upstream detail')));
            Route::get('boom', fn () => throw new \RuntimeException('SQLSTATE secret internals'));
        });
    }

    public function test_business_rule_returns_409_with_code(): void
    {
        $this->getJson('/api/v1/_test/business')->assertStatus(409)
            ->assertJson(['success' => false, 'error_code' => 'voyage_already_finalized', 'message' => 'Voyage already finalized.'])
            ->assertJsonStructure(['request_id']);
    }

    public function test_integration_failure_returns_502_without_upstream_detail(): void
    {
        $res = $this->getJson('/api/v1/_test/integration')->assertStatus(502)->assertJsonPath('error_code', 'integration_failed');
        $this->assertStringNotContainsString('secret upstream', $res->getContent());
    }

    public function test_unexpected_error_hides_internals_when_debug_off(): void
    {
        config(['app.debug' => false]);

        $res = $this->getJson('/api/v1/_test/boom')->assertStatus(500)->assertJsonPath('error_code', 'server_error');
        $this->assertStringNotContainsString('SQLSTATE', $res->getContent());
    }

    public function test_unauthenticated_api_request_without_json_accept_header_is_401_not_500(): void
    {
        $this->get('/api/v1/auth/me')->assertStatus(401)->assertJsonPath('error_code', 'unauthenticated');
    }

    public function test_incoming_request_id_is_echoed(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        $this->getJson('/api/v1/auth/me', ['X-Request-Id' => 'abc12345-test'])->assertHeader('X-Request-Id', 'abc12345-test');
    }
}
