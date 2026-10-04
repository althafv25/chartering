<?php

namespace Tests\Feature\Operations;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\Feature\Contracts\ContractTestCase;

abstract class OperationsTestCase extends ContractTestCase
{
    protected User $ops;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 12:00:00');
        $this->ops = $this->userWithRole(UserRole::Operations);
        $this->ops->forceFill(['timezone' => 'Asia/Dubai'])->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Approved fixture → voyage (by operations). @return int voyage id */
    protected function voyage(): int
    {
        $fx = $this->approvedFixture();

        return $this->as($this->ops)->postJson("/api/v1/fixtures/{$fx}/convert-to-voyage")->assertCreated()->json('data.id');
    }

    /** Voyage moved to sailing, commenced 2026-10-14 08:00 Dubai (04:00Z). */
    protected function sailingVoyage(): int
    {
        $id = $this->voyage();
        $this->postJson("/api/v1/voyages/{$id}/transition", ['status' => 'nominated', 'at' => '2026-10-13T08:00'])->assertOk();
        $this->postJson("/api/v1/voyages/{$id}/transition", ['status' => 'sailing', 'at' => '2026-10-14T08:00'])->assertOk();

        return $id;
    }

    /** @return array<string, mixed> port call data */
    protected function portCall(int $voyage, array $data = []): array
    {
        return $this->postJson("/api/v1/voyages/{$voyage}/port-calls", ['port_id' => $this->ports[0], 'purpose' => 'load', ...$data])->assertCreated()->json('data');
    }
}
