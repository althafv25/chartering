<?php

namespace App\Console\Commands\Concerns;

use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use RuntimeException;

/** In-process API client used by the demo-data loaders (same validation and workflow rules as the UI). */
trait DrivesApi
{
    private function as(User $user): static
    {
        Sanctum::actingAs($user);

        return $this;
    }

    /**
     * Calls the API in-process as the current user and returns the `data` payload.
     *
     * @return array<string, mixed>
     */
    private function api(string $method, string $uri, array $body = [], int $status = 200): array
    {
        $kernel = app(Kernel::class);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $request = Request::create($uri, $method, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
                $method === 'GET' ? null : json_encode($body));
            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);
            if ($response->getStatusCode() === 429) {
                sleep(20);

                continue;
            }
            if ($response->getStatusCode() !== $status) {
                throw new RuntimeException("{$method} {$uri} returned {$response->getStatusCode()} (expected {$status}): ".mb_substr((string) $response->getContent(), 0, 600));
            }
            $json = json_decode((string) $response->getContent(), true);

            return is_array($json) ? ($json['data'] ?? []) : [];
        }

        throw new RuntimeException("{$method} {$uri} was rate limited.");
    }

    private function expect(string $expected, mixed $actual, string $what): void
    {
        if ($expected !== (string) $actual) {
            throw new RuntimeException("{$what}: expected {$expected} but got {$actual}.");
        }
    }
}
