<?php

namespace Tests\Feature\Operations;

use App\Enums\UserRole;
use App\Models\ExpenseCategory;
use App\Models\LaytimeCalculation;
use App\Models\PortDa;
use App\Models\RevenueCategory;
use App\Models\VoyageRevenue;
use App\Models\VoyageSnapshot;

class VoyageFinalizationGatesTest extends OperationsTestCase
{
    public function test_finalization_freezes_financial_lines_until_reopened(): void
    {
        $id = $this->completedVoyage();
        $finance = $this->userWithRole(UserRole::Finance);
        $revenue = ['voyage_id' => $id, 'revenue_category_id' => RevenueCategory::query()->value('id'), 'description' => 'Freight', 'amount' => '100', 'currency' => 'USD'];
        $expense = ['voyage_id' => $id, 'expense_category_id' => ExpenseCategory::query()->value('id'), 'description' => 'Costs', 'amount' => '50', 'currency' => 'USD'];
        $rid = $this->as($finance)->postJson('/api/v1/voyage-revenues', $revenue)->assertCreated()->json('data.id');
        $eid = $this->postJson('/api/v1/voyage-expenses', $expense)->assertCreated()->json('data.id');
        $this->as($this->ops)->postJson("/api/v1/voyages/{$id}/finalize")->assertOk();
        $snapshot = VoyageSnapshot::query()->where('voyage_id', $id)->where('type', 'final')->firstOrFail();
        $payload = $snapshot->payload;
        foreach ([['voyage-revenues', $rid, $revenue], ['voyage-expenses', $eid, $expense]] as [$resource, $line, $body]) {
            $this->as($finance)->postJson("/api/v1/{$resource}", $body)->assertStatus(409)->assertJsonPath('error_code', 'voyage_read_only');
            $this->putJson("/api/v1/{$resource}/{$line}", ['description' => 'Changed'])->assertStatus(409);
            $this->postJson("/api/v1/{$resource}/{$line}/confirm")->assertStatus(409);
            $this->postJson("/api/v1/{$resource}/{$line}/cancel", ['reason' => 'Correction'])->assertStatus(409);
            $this->deleteJson("/api/v1/{$resource}/{$line}")->assertStatus(409);
        }
        $this->assertSame($payload, $snapshot->refresh()->payload);
        $this->as($this->ops)->postJson("/api/v1/voyages/{$id}/reopen", ['reason' => 'Financial correction'])->assertOk();
        $this->as($finance)->postJson('/api/v1/voyage-revenues', $revenue)->assertCreated();
        $this->assertSame($payload, $snapshot->refresh()->payload);
    }

    public function test_voyage_cancellation_requires_existing_invoices_to_be_credited(): void
    {
        $id = $this->sailingVoyage();
        $finance = $this->userWithRole(UserRole::Finance);
        $invoice = $this->as($finance)->postJson('/api/v1/invoices', [
            'voyage_id' => $id, 'customer_company_id' => $this->client->id, 'invoice_type' => 'freight',
            'issue_date' => '2026-10-20', 'due_date' => '2026-11-20', 'currency' => 'USD',
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/invoices/{$invoice}/lines", ['lines' => [['description' => 'Freight', 'amount' => '100']]])->assertOk();
        $this->postJson("/api/v1/invoices/{$invoice}/issue")->assertOk();
        $this->as($this->ops)->postJson("/api/v1/voyages/{$id}/cancel", ['reason' => 'Voyage cancelled'])->assertStatus(409)->assertJsonPath('error_code', 'voyage_has_invoices');
        $this->as($finance)->postJson("/api/v1/invoices/{$invoice}/credit-note", ['reason' => 'Voyage cancelled'])->assertCreated();
        $this->as($this->ops)->postJson("/api/v1/voyages/{$id}/cancel", ['reason' => 'Voyage cancelled'])->assertOk();
        $this->as($finance)->postJson('/api/v1/voyage-revenues', ['voyage_id' => $id, 'revenue_category_id' => RevenueCategory::query()->value('id'), 'description' => 'Late freight', 'amount' => '100', 'currency' => 'USD'])
            ->assertStatus(409)->assertJsonPath('error_code', 'voyage_read_only');
    }

    /** OP-04: a confirmed revenue line not yet invoiced blocks finalize unless waived. */
    public function test_revenue_gate_blocks_finalize_until_invoiced_or_waived(): void
    {
        $id = $this->completedVoyage();
        $categoryId = RevenueCategory::query()->where('code', 'FREIGHT')->value('id');
        VoyageRevenue::query()->create([
            'voyage_id' => $id, 'revenue_category_id' => $categoryId, 'description' => 'Freight', 'currency' => 'USD',
            'fx_rate' => '1', 'amount' => '1000.00', 'base_amount' => '1000.00', 'status' => 'confirmed',
        ]);

        $gates = $this->getJson("/api/v1/voyages/{$id}/finance-gates")->assertOk()->json('data');
        $this->assertFalse($gates['revenue']['passed']);
        $this->assertTrue($gates['ledger']['passed']);
        $this->assertTrue($gates['port_da']['passed']);
        $this->assertTrue($gates['laytime']['passed']);

        $this->postJson("/api/v1/voyages/{$id}/finalize")->assertStatus(409)
            ->assertJsonPath('error_code', 'voyage_not_finalizable')->assertJsonStructure(['errors' => ['revenue']]);

        // Too-short reason is rejected.
        $this->postJson("/api/v1/voyages/{$id}/finalize", ['waivers' => ['revenue' => 'later']])->assertStatus(409);

        $final = $this->postJson("/api/v1/voyages/{$id}/finalize", ['waivers' => ['revenue' => 'Invoiced next month, approved by finance manager']])
            ->assertOk()->json('data');
        $this->assertSame('finalized', $final['status']);
        $this->assertArrayHasKey('revenue', $final['finalization_waivers']);
        $this->assertSame('Invoiced next month, approved by finance manager', $final['finalization_waivers']['revenue']['reason']);
    }

    /** OP-04: an un-approved final Port DA blocks finalize unless waived. */
    public function test_port_da_gate_blocks_finalize_until_approved_or_waived(): void
    {
        [$id, $callId] = $this->completedVoyageWithCall();
        PortDa::query()->create([
            'da_number' => 'DA-TEST-1', 'port_call_id' => $callId, 'voyage_id' => $id, 'port_id' => $this->ports[0],
            'da_type' => 'final', 'currency' => 'USD', 'total_amount' => '500.00', 'base_amount' => '500.00', 'status' => 'submitted',
        ]);

        $gates = $this->getJson("/api/v1/voyages/{$id}/finance-gates")->assertOk()->json('data');
        $this->assertFalse($gates['port_da']['passed']);

        $this->postJson("/api/v1/voyages/{$id}/finalize")->assertStatus(409)->assertJsonStructure(['errors' => ['port_da']]);

        $final = $this->postJson("/api/v1/voyages/{$id}/finalize", ['waivers' => ['port_da' => 'Agent confirmed final DA verbally, written copy pending']])
            ->assertOk()->json('data');
        $this->assertSame('finalized', $final['status']);
        $this->assertArrayHasKey('port_da', $final['finalization_waivers']);
    }

    /** OP-04: a non-agreed laytime calculation blocks finalize unless waived. */
    public function test_laytime_gate_blocks_finalize_until_agreed_or_waived(): void
    {
        [$id, $callId] = $this->completedVoyageWithCall();
        LaytimeCalculation::query()->create([
            'port_call_id' => $callId, 'voyage_id' => $id, 'calculation_type' => 'load', 'status' => 'submitted',
        ]);

        $gates = $this->getJson("/api/v1/voyages/{$id}/finance-gates")->assertOk()->json('data');
        $this->assertFalse($gates['laytime']['passed']);

        $this->postJson("/api/v1/voyages/{$id}/finalize")->assertStatus(409)->assertJsonStructure(['errors' => ['laytime']]);

        $final = $this->postJson("/api/v1/voyages/{$id}/finalize", ['waivers' => ['laytime' => 'Charterer disputes rate, under negotiation']])
            ->assertOk()->json('data');
        $this->assertSame('finalized', $final['status']);
        $this->assertArrayHasKey('laytime', $final['finalization_waivers']);
    }

    /** All gates pass with no outstanding records: finalize proceeds with no waivers recorded. */
    public function test_finalize_succeeds_without_waivers_when_all_gates_pass(): void
    {
        $id = $this->completedVoyage();

        $final = $this->postJson("/api/v1/voyages/{$id}/finalize")->assertOk()->json('data');
        $this->assertSame('finalized', $final['status']);
        $this->assertNull($final['finalization_waivers']);
    }

    /** @return int voyage id, completed and ready to finalize */
    private function completedVoyage(): int
    {
        $id = $this->sailingVoyage();
        $call = $this->portCall($id, ['eta' => '2026-10-15T06:00']);
        $this->putJson("/api/v1/voyages/{$id}/port-calls/{$call['id']}", ['lock_version' => $call['lock_version'], 'ata' => '2026-10-15T08:00', 'atd' => '2026-10-16T20:00'])->assertOk();
        $this->postJson("/api/v1/voyages/{$id}/complete", ['at' => '2026-10-19T10:00'])->assertOk();

        return $id;
    }

    /** @return array{0: int, 1: int} [voyage id, sailed port call id], completed and ready to finalize */
    private function completedVoyageWithCall(): array
    {
        $id = $this->sailingVoyage();
        $call = $this->portCall($id, ['eta' => '2026-10-15T06:00']);
        $this->putJson("/api/v1/voyages/{$id}/port-calls/{$call['id']}", ['lock_version' => $call['lock_version'], 'ata' => '2026-10-15T08:00', 'atd' => '2026-10-16T20:00'])->assertOk();
        $this->postJson("/api/v1/voyages/{$id}/complete", ['at' => '2026-10-19T10:00'])->assertOk();

        return [$id, $call['id']];
    }
}
