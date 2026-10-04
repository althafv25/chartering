<?php

namespace Tests\Feature\Operations;

use App\Models\VoyageSnapshot;

class VoyageLifecycleTest extends OperationsTestCase
{
    public function test_operational_moves_set_commencement_and_reject_invalid_transitions(): void
    {
        $id = $this->voyage();
        $this->getJson("/api/v1/voyages/{$id}")->assertOk()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.allowed_transitions', ['nominated']);

        $this->postJson("/api/v1/voyages/{$id}/transition", ['status' => 'sailing'])->assertStatus(409)->assertJsonPath('error_code', 'invalid_status_transition');
        $this->postJson("/api/v1/voyages/{$id}/transition", ['status' => 'nominated', 'at' => '2026-10-13T08:00'])->assertOk()->assertJsonPath('data.commenced_at', null);
        // Back-dating before the previous status change is refused; future times too.
        $this->postJson("/api/v1/voyages/{$id}/transition", ['status' => 'sailing', 'at' => '2026-10-12T08:00'])->assertStatus(422);
        $this->postJson("/api/v1/voyages/{$id}/transition", ['status' => 'sailing', 'at' => '2026-10-25T08:00'])->assertStatus(422);
        $this->postJson("/api/v1/voyages/{$id}/transition", ['status' => 'sailing', 'at' => '2026-10-14T08:00'])->assertOk()
            ->assertJsonPath('data.status', 'sailing')->assertJsonPath('data.commenced_at', '2026-10-14T04:00:00+00:00');

        $this->assertDatabaseHas('activity_log', ['subject_type' => 'voyages', 'subject_id' => $id, 'event' => 'status_changed']);
    }

    public function test_complete_requires_closed_port_calls_and_reports_then_finalize_and_reopen(): void
    {
        $id = $this->sailingVoyage();
        $call = $this->portCall($id, ['eta' => '2026-10-15T06:00']);

        $this->postJson("/api/v1/voyages/{$id}/complete")->assertStatus(409)->assertJsonPath('error_code', 'voyage_not_completable')
            ->assertJsonStructure(['errors' => ['port_calls']]);

        $this->putJson("/api/v1/voyages/{$id}/port-calls/{$call['id']}", ['lock_version' => $call['lock_version'], 'ata' => '2026-10-15T08:00', 'atd' => '2026-10-16T20:00'])
            ->assertOk()->assertJsonPath('data.status', 'sailed');

        $this->postJson("/api/v1/voyages/{$id}/complete", ['at' => '2026-10-19T10:00'])->assertOk()
            ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.completed_at', '2026-10-19T06:00:00+00:00');

        $this->postJson("/api/v1/voyages/{$id}/finalize")->assertOk()->assertJsonPath('data.status', 'finalized');
        $this->assertSame(1, VoyageSnapshot::query()->where('voyage_id', $id)->where('type', 'final')->count());
        $final = VoyageSnapshot::query()->where('voyage_id', $id)->where('type', 'final')->firstOrFail();
        $this->assertSame('5.08', $final->payload['metrics']['total_days']); // 14 Oct 04:00Z → 19 Oct 06:00Z = 5d 2h
        $this->assertSame('1.50', $final->payload['metrics']['port_days']);

        // OP-05: read-only once finalized.
        $this->portCallFails($id);
        $this->postJson("/api/v1/voyages/{$id}/reopen", ['reason' => 'x'])->assertStatus(422);
        $this->postJson("/api/v1/voyages/{$id}/reopen", ['reason' => 'Late agency invoice correction'])->assertOk()
            ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.reopened_count', 1);
        $this->assertSame(0, VoyageSnapshot::query()->where('voyage_id', $id)->where('type', 'final')->count());
        $this->assertDatabaseHas('voyage_snapshots', ['voyage_id' => $id, 'type' => 'milestone', 'name' => 'Final before reopen #1']);

        $this->postJson("/api/v1/voyages/{$id}/finalize")->assertOk();
        $this->assertDatabaseHas('voyage_snapshots', ['voyage_id' => $id, 'type' => 'final', 'name' => 'Final (#1)']);
    }

    public function test_cancel_needs_reason_and_is_refused_after_completion(): void
    {
        $id = $this->voyage();
        $this->postJson("/api/v1/voyages/{$id}/cancel", [])->assertStatus(422);
        $this->postJson("/api/v1/voyages/{$id}/cancel", ['reason' => 'Charterer withdrew'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson("/api/v1/voyages/{$id}/cancel", ['reason' => 'again'])->assertStatus(409);
        $this->portCallFails($id);
    }

    public function test_permissions(): void
    {
        $id = $this->voyage();
        $this->as($this->charterer)->getJson("/api/v1/voyages/{$id}")->assertOk();
        $this->postJson("/api/v1/voyages/{$id}/transition", ['status' => 'nominated'])->assertForbidden();
        $this->postJson("/api/v1/voyages/{$id}/port-calls", ['port_id' => $this->ports[0], 'purpose' => 'load'])->assertForbidden();
        $this->as($this->manager)->postJson("/api/v1/voyages/{$id}/finalize")->assertForbidden();
    }

    public function test_milestone_snapshot_and_comparison(): void
    {
        $id = $this->sailingVoyage();
        $this->postJson("/api/v1/voyages/{$id}/snapshots", ['name' => 'After loading'])->assertCreated();

        $cmp = $this->getJson("/api/v1/voyages/{$id}/comparison")->assertOk()->json('data');
        $this->assertSame(['initial', 'milestone', 'current'], array_column($cmp['columns'], 'type'));
        $rows = collect($cmp['rows'])->keyBy('metric');
        $this->assertNotNull($rows['total_days']['values']['initial']);
        $this->assertSame('6.33', $rows['total_days']['values']['current']); // 14 Oct 04:00Z → 20 Oct 12:00Z
        $this->assertNotNull($rows['total_days']['variance']['current']);
        $this->assertSame($rows['profit']['values']['initial'], $rows['profit']['values']['current']); // financials carried from estimate
        $this->assertSame('estimate', $cmp['financials_source']);
    }

    private function portCallFails(int $id): void
    {
        $this->postJson("/api/v1/voyages/{$id}/port-calls", ['port_id' => $this->ports[0], 'purpose' => 'load'])->assertStatus(409)
            ->assertJsonPath('error_code', 'voyage_read_only');
    }
}
