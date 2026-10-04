<?php

namespace Tests\Feature\Operations;

use App\Enums\VoyageExpenseStatus;
use App\Models\DaCostCategory;
use App\Models\PortDa;
use App\Models\VoyageExpense;
use App\Services\Finance\VoyageExpenseService;

class PortDaTest extends OperationsTestCase
{
    public function test_da_must_reference_a_port_call_of_its_own_voyage_and_port(): void
    {
        $id = $this->sailingVoyage();
        $call = $this->portCall($id);
        $other = $this->sailingVoyage();   // a second voyage: the call does not belong to it

        $body = ['port_call_id' => $call['id'], 'voyage_id' => $id, 'port_id' => $this->ports[0], 'currency' => 'USD', 'da_type' => 'proforma'];
        $this->postJson('/api/v1/port-das', [...$body, 'voyage_id' => $other])->assertStatus(422)->assertJsonValidationErrors('port_call_id');
        $this->postJson('/api/v1/port-das', [...$body, 'port_id' => $this->ports[1]])->assertStatus(422)->assertJsonValidationErrors('port_id');
        $this->postJson('/api/v1/port-das', $body)->assertCreated();
    }

    public function test_da_lifecycle_items_and_approval(): void
    {
        $id = $this->sailingVoyage();
        $call = $this->portCall($id);
        $category = DaCostCategory::query()->where('code', 'AGENCY')->value('id');

        $da = $this->postJson('/api/v1/port-das', [
            'port_call_id' => $call['id'],
            'voyage_id' => $id,
            'port_id' => $this->ports[0],
            'currency' => 'USD',
            'da_type' => 'proforma',
        ])->assertCreated()->json('data');
        $this->assertMatchesRegularExpression('/^DA-2026-\d{5}$/', $da['da_number']);
        $this->assertSame('draft', $da['status']);
        $this->assertTrue($da['is_editable']);

        $url = "/api/v1/port-das/{$da['id']}";
        $updated = $this->putJson($url, ['lock_version' => $da['lock_version'], 'remarks' => 'Initial estimate'])
            ->assertOk()->assertJsonPath('data.remarks', 'Initial estimate')->json('data');

        $withItems = $this->postJson("{$url}/items", ['items' => [
            ['da_cost_category_id' => $category, 'description' => 'Agency fee', 'estimated_amount' => '1000.00', 'actual_amount' => '1200.50'],
        ]])->assertOk()->json('data');
        $this->assertCount(1, $withItems['items']);
        $this->assertSame('200.50', $withItems['items'][0]['variance_amount']);
        // da_type is proforma → total uses estimated_amount
        $this->assertSame('1000.00', $withItems['total_amount']);

        $this->postJson("{$url}/submit")->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->postJson("{$url}/approve")->assertForbidden();
        $this->as($this->commercial)->postJson("{$url}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

        $this->as($this->ops)->putJson($url, ['lock_version' => $updated['lock_version'] + 1, 'remarks' => 'blocked'])->assertStatus(409);
    }

    public function test_da_reject_returns_to_draft_and_filters_work(): void
    {
        $id = $this->sailingVoyage();
        $call = $this->portCall($id);
        $da = $this->postJson('/api/v1/port-das', ['port_call_id' => $call['id'], 'voyage_id' => $id, 'port_id' => $this->ports[0], 'currency' => 'USD', 'da_type' => 'final'])
            ->assertCreated()->json('data');
        $this->postJson("/api/v1/port-das/{$da['id']}/submit")->assertOk();
        $this->as($this->commercial)->postJson("/api/v1/port-das/{$da['id']}/reject")->assertOk()->assertJsonPath('data.status', 'draft');

        $list = $this->getJson("/api/v1/port-das?voyage_id={$id}&da_type=final")->assertOk()->json('data');
        $this->assertCount(1, $list);

        $this->as($this->ops)->postJson('/api/v1/port-das', [])->assertStatus(422);
        $this->as($this->charterer)->postJson('/api/v1/port-das', [])->assertForbidden();
    }

    /** DA-02: approving a final DA creates voyage expenses from its items (source port_da); re-approval updates them in place while not paid. */
    public function test_approving_final_da_creates_voyage_expenses_and_reapproval_updates_in_place(): void
    {
        $id = $this->sailingVoyage();
        $call = $this->portCall($id);
        $agency = DaCostCategory::query()->where('code', 'AGENCY')->value('id');
        $pilot = DaCostCategory::query()->where('code', 'PILOT')->value('id');

        $da = $this->postJson('/api/v1/port-das', [
            'port_call_id' => $call['id'], 'voyage_id' => $id, 'port_id' => $this->ports[0], 'currency' => 'USD', 'da_type' => 'final',
        ])->assertCreated()->json('data');

        $url = "/api/v1/port-das/{$da['id']}";
        $this->postJson("{$url}/items", ['items' => [
            ['da_cost_category_id' => $agency, 'description' => 'Agency fee', 'estimated_amount' => '1000.00', 'actual_amount' => '1200.50'],
            ['da_cost_category_id' => $pilot, 'description' => 'Pilotage', 'estimated_amount' => '300.00', 'actual_amount' => '350.00'],
        ]])->assertOk();

        $this->postJson("{$url}/submit")->assertOk();
        $this->as($this->commercial)->postJson("{$url}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

        $expenses = VoyageExpense::query()->where('source_type', 'port-das')->where('source_id', $da['id'])->orderBy('id')->get();
        $this->assertCount(2, $expenses);
        $this->assertSame('1200.50', $expenses[0]->amount);
        $this->assertSame(VoyageExpenseStatus::Confirmed, $expenses[0]->status);
        $this->assertSame($id, $expenses[0]->voyage_id);
        $this->assertSame('350.00', $expenses[1]->amount);
    }

    /** DA-02: calling the sync again (e.g. on re-approval) updates the linked expense in place rather than duplicating it, and never touches a paid one. */
    public function test_resyncing_port_da_updates_expense_in_place_and_skips_paid(): void
    {
        $id = $this->sailingVoyage();
        $call = $this->portCall($id);
        $agency = DaCostCategory::query()->where('code', 'AGENCY')->value('id');
        $pilot = DaCostCategory::query()->where('code', 'PILOT')->value('id');

        $da = $this->postJson('/api/v1/port-das', [
            'port_call_id' => $call['id'], 'voyage_id' => $id, 'port_id' => $this->ports[0], 'currency' => 'USD', 'da_type' => 'final',
        ])->assertCreated()->json('data');
        $url = "/api/v1/port-das/{$da['id']}";
        $this->postJson("{$url}/items", ['items' => [
            ['da_cost_category_id' => $agency, 'description' => 'Agency fee', 'estimated_amount' => '1000.00', 'actual_amount' => '1200.50'],
            ['da_cost_category_id' => $pilot, 'description' => 'Pilotage', 'estimated_amount' => '300.00', 'actual_amount' => '350.00'],
        ]])->assertOk();
        $this->postJson("{$url}/submit")->assertOk();
        $this->as($this->commercial)->postJson("{$url}/approve")->assertOk();

        $expenses = VoyageExpense::query()->where('source_type', 'port-das')->where('source_id', $da['id'])->orderBy('id')->get();
        $this->assertCount(2, $expenses);
        $agencyExpenseId = $expenses[0]->id;
        $pilotExpenseId = $expenses[1]->id;

        // Mark the agency expense as paid; it must be left untouched by a future sync.
        /** @var VoyageExpenseService $expenseService */
        $expenseService = app(VoyageExpenseService::class);
        $expenseService->markPaid(VoyageExpense::query()->findOrFail($agencyExpenseId));

        // Correct the actual amounts directly on the DA items (simulating a re-approval scenario) and re-sync.
        $portDa = PortDa::query()->findOrFail($da['id']);
        $portDa->items()->where('da_cost_category_id', $agency)->update(['actual_amount' => '1300.00']);
        $portDa->items()->where('da_cost_category_id', $pilot)->update(['actual_amount' => '375.25']);
        $expenseService->syncFromPortDa($portDa->fresh('items.category'), $this->commercial);

        $this->assertSame(2, VoyageExpense::query()->where('source_type', 'port-das')->where('source_id', $da['id'])->count());

        $agencyExpense = VoyageExpense::query()->findOrFail($agencyExpenseId);
        $this->assertSame('1200.50', $agencyExpense->amount, 'paid expense must not be modified by a re-sync');
        $this->assertSame(VoyageExpenseStatus::Paid, $agencyExpense->status);

        $pilotExpense = VoyageExpense::query()->findOrFail($pilotExpenseId);
        $this->assertSame('375.25', $pilotExpense->amount, 'not-yet-paid expense is updated in place');
    }

    /** The DA screen posts every row with null for empty cells and replaces the whole item list each time. */
    public function test_item_rows_with_empty_cells_are_accepted_and_replace_the_list(): void
    {
        $id = $this->sailingVoyage();
        $call = $this->portCall($id);
        $category = DaCostCategory::query()->where('code', 'AGENCY')->value('id');
        $da = $this->postJson('/api/v1/port-das', ['port_call_id' => $call['id'], 'voyage_id' => $id, 'port_id' => $this->ports[0], 'currency' => 'USD', 'da_type' => 'final', 'proforma_da_id' => null])
            ->assertCreated()->json('data');

        $saved = $this->postJson("/api/v1/port-das/{$da['id']}/items", ['items' => [
            ['da_cost_category_id' => $category, 'description' => null, 'estimated_amount' => null, 'actual_amount' => '1200.50', 'remarks' => null],
            ['da_cost_category_id' => $category, 'description' => 'Pilotage', 'estimated_amount' => '300', 'actual_amount' => null, 'remarks' => null],
        ]])->assertOk()->json('data');
        $this->assertCount(2, $saved['items']);
        $this->assertSame('1200.50', $saved['total_amount']);   // final DA totals the actual amounts

        $replaced = $this->postJson("/api/v1/port-das/{$da['id']}/items", ['items' => [['da_cost_category_id' => $category, 'description' => 'Agency', 'estimated_amount' => null, 'actual_amount' => '10.00', 'remarks' => null]]])
            ->assertOk()->json('data');
        $this->assertCount(1, $replaced['items']);
        $this->assertSame('10.00', $replaced['total_amount']);

        $this->postJson("/api/v1/port-das/{$da['id']}/items", ['items' => [['da_cost_category_id' => $category, 'actual_amount' => '12.345']]])->assertStatus(422);
    }
}
