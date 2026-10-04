<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sweeps every API route (docs/11): anonymous callers are refused, and a signed-in user with no role/permissions
 * is never served (no 2xx) and never triggers a server error (no 5xx) — including with empty input.
 * A new endpoint that forgets its authorization check fails this test.
 * Limit: for routes with a model parameter, route binding answers 404 before authorization when record 1 does not
 * exist, so those are covered by the per-module permission tests rather than here.
 */
class RouteAccessTest extends TestCase
{
    use RefreshDatabase;

    /** Routes that are public by design. */
    private const PUBLIC = ['api/v1/auth/login', 'api/v1/auth/forgot-password', 'api/v1/auth/reset-password'];

    /** Self-service routes any signed-in user may call (their own account / notifications). "METHOD uri" => allowed to return 2xx. */
    private const SELF_SERVICE = [
        'GET api/v1/auth/me', 'POST api/v1/auth/logout', 'PUT api/v1/profile',
        'GET api/v1/notifications', 'GET api/v1/notifications/unread-count', 'POST api/v1/notifications/read-all',
        'GET api/v1/document-types',
        // Deliberate lookups for dropdowns: active currencies / reference items only (the full lists need currencies.view / masters.view) and a static list of status names.
        'GET api/v1/currencies', 'GET api/v1/vessel-status/catalogue', 'GET api/v1/reference/{type}',
    ];

    /** @return list<array{method: string, uri: string}> */
    private function routes(): array
    {
        $out = [];
        foreach (Route::getRoutes() as $route) {
            /** @var LaravelRoute $route */
            if (! str_starts_with($route->uri(), 'api/v1')) {
                continue;
            }
            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }
                $wheres = $route->wheres;
                $uri = preg_replace_callback('/\{(\w+)\??\}/', fn (array $m) => $this->sample($m[1], $wheres[$m[1]] ?? null), $route->uri());
                $out[] = ['method' => $method, 'uri' => (string) $uri, 'pattern' => $route->uri()];
            }
        }

        return $out;
    }

    /** A path value that satisfies the route's own constraint (slug lists, digits), defaulting to id 1. */
    private function sample(string $parameter, ?string $constraint): string
    {
        if ($parameter === 'parentType') {
            return 'vessels';
        }
        if ($constraint !== null && preg_match('/^[a-z0-9_\-]+(\|[a-z0-9_\-]+)+$/', $constraint)) {
            return explode('|', $constraint)[0];
        }

        if ($constraint !== null && str_contains($constraint, 'a-z')) {
            return 'fuel-types';   // a valid reference-data slug, so authorization (not routing) is what gets exercised
        }

        return '1';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();   // permissions must exist, as after a real deploy
    }

    public function test_anonymous_callers_are_refused_everywhere_except_the_public_routes(): void
    {
        $checked = 0;
        foreach ($this->routes() as $r) {
            if (in_array($r['pattern'], self::PUBLIC, true)) {
                continue;
            }
            $status = $this->json($r['method'], '/'.$r['uri'])->getStatusCode();
            $this->assertSame(401, $status, "{$r['method']} {$r['pattern']} answered {$status} to an anonymous request");
            $checked++;
        }
        $this->assertGreaterThan(250, $checked);
    }

    public function test_a_user_without_permissions_is_never_served_and_never_crashes_the_server(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        User::factory()->create();            // takes id 1, so '{user}' = 1 is someone else
        $user = User::factory()->create();    // active, no role, no permissions
        Sanctum::actingAs($user);

        $problems = [];
        foreach ($this->routes() as $r) {
            if (in_array($r['pattern'], self::PUBLIC, true)) {
                continue;
            }
            $status = $this->json($r['method'], '/'.$r['uri'])->getStatusCode();
            $allowed = in_array("{$r['method']} {$r['pattern']}", self::SELF_SERVICE, true);
            if ($status >= 500 || ($status >= 200 && $status < 300 && ! $allowed)) {
                $problems[] = "{$r['method']} {$r['pattern']} → {$status}";
            }
        }

        $this->assertSame([], $problems, "Routes that served a permissionless user or crashed:\n".implode("\n", $problems));
    }
}
