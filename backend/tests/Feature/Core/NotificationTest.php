<?php

namespace Tests\Feature\Core;

use App\Enums\UserRole;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
    }

    public function test_user_sees_only_own_notifications_and_can_mark_read(): void
    {
        $service = app(NotificationService::class);
        $me = $this->actingAsRole(UserRole::Operations);
        $other = $this->userWithRole(UserRole::Operations);

        $mine = $service->send($me, 'test', 'Hello', 'World');
        $theirs = $service->send($other, 'test', 'Secret', 'Not yours');

        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/notifications/unread-count')->assertJsonPath('data.count', 1);

        $this->postJson("/api/v1/notifications/{$theirs->id}/read")->assertNotFound();
        $this->postJson("/api/v1/notifications/{$mine->id}/read")->assertOk();
        $this->getJson('/api/v1/notifications/unread-count')->assertJsonPath('data.count', 0);
    }

    public function test_super_admin_cannot_read_others_notifications(): void
    {
        $service = app(NotificationService::class);
        $other = $this->userWithRole(UserRole::Finance);
        $n = $service->send($other, 'test', 'Private', 'x');

        $this->actingAsRole(UserRole::SuperAdmin);
        $this->postJson("/api/v1/notifications/{$n->id}/read")->assertNotFound();
    }

    public function test_dedupe_key_prevents_duplicates(): void
    {
        $service = app(NotificationService::class);
        $user = $this->userWithRole(UserRole::Finance);

        $this->assertNotNull($service->send($user, 'expiry', 'A', 'B', dedupeKey: 'contract:1:30d'));
        $this->assertNull($service->send($user, 'expiry', 'A', 'B', dedupeKey: 'contract:1:30d'));
        $this->assertSame(1, $user->appNotifications()->count());
    }

    public function test_mark_all_read(): void
    {
        $service = app(NotificationService::class);
        $me = $this->actingAsRole(UserRole::Finance);
        $service->send($me, 'a', 'x', 'y');
        $service->send($me, 'a', 'x', 'z');

        $this->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('data.updated', 2);
    }
}
