<?php

namespace Tests\Feature\Operations;

class LaytimeTest extends OperationsTestCase
{
    public function test_laytime_calculation_sof_events_exceptions_and_lifecycle(): void
    {
        $id = $this->sailingVoyage();
        $call = $this->portCall($id);

        $calc = $this->postJson('/api/v1/laytime-calculations', [
            'port_call_id' => $call['id'],
            'voyage_id' => $id,
            'calculation_type' => 'load',
            'cargo_quantity' => '10000.000',
            'rate_per_day' => '5000.0000',
            'rate_unit' => 'MT',
            'demurrage_rate_per_day' => '15000.0000',
            'despatch_rate_per_day' => '7500.0000',
            'currency' => 'USD',
            'nor_tendered_at' => '2026-10-15T06:00',
            'nor_accepted_at' => '2026-10-15T06:00',
            'notice_time_hours' => '6',
            'laytime_commenced_at' => '2026-10-15T12:00',
            'laytime_completed_at' => '2026-10-17T12:00',
        ])->assertCreated()->json('data');
        $this->assertSame('draft', $calc['status']);
        $this->assertTrue($calc['is_editable']);
        $this->assertSame('2026-10-15T02:00:00+00:00', $calc['nor_tendered_at']); // Dubai local → UTC
        // The API also returns the port-local value and zone, so clients never do timezone maths (G-06).
        $this->assertSame('Asia/Dubai', $calc['timezone']);
        $this->assertSame('2026-10-15T06:00', $calc['nor_tendered_at_local']);
        $this->assertSame('2026-10-17T12:00', $calc['laytime_completed_at_local']);

        $url = "/api/v1/laytime-calculations/{$calc['id']}";

        $withEvent = $this->postJson("{$url}/sof-events", [
            'event_at' => '2026-10-15T12:00',
            'event_code' => 'COMMENCED',
            'description' => 'Laytime commenced',
        ])->assertCreated()->json('data');
        $this->assertCount(1, $withEvent['sof_events']);
        $this->assertSame('2026-10-15T12:00', $withEvent['sof_events'][0]['event_at_local']);
        $eventId = $withEvent['sof_events'][0]['id'];

        $withException = $this->postJson("{$url}/exceptions", [
            'from_at' => '2026-10-16T00:00',
            'to_at' => '2026-10-16T06:00',
            'exception_type' => 'weather',
            'pct_counted' => '0',
        ])->assertCreated()->json('data');
        $this->assertCount(1, $withException['exceptions']);
        $this->assertSame(['2026-10-16T00:00', '2026-10-16T06:00'], [$withException['exceptions'][0]['from_at_local'], $withException['exceptions'][0]['to_at_local']]);
        $exceptionId = $withException['exceptions'][0]['id'];

        $updatedException = $this->putJson("{$url}/exceptions/{$exceptionId}", ['remarks' => 'Heavy rain'])
            ->assertOk()->json('data');
        $this->assertSame('Heavy rain', $updatedException['exceptions'][0]['remarks']);

        $calculated = $this->postJson("{$url}/calculate")->assertOk()->json('data');
        $this->assertNotNull($calculated['allowed_hours']);
        $this->assertNotNull($calculated['used_hours']);
        $this->assertNotNull($calculated['trace']);

        $this->postJson("{$url}/submit")->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->postJson("{$url}/agree")->assertForbidden();
        $this->as($this->commercial)->postJson("{$url}/agree")->assertOk()->assertJsonPath('data.status', 'agreed');

        $this->as($this->ops)->deleteJson("{$url}/sof-events/{$eventId}")->assertStatus(409); // no longer editable once agreed
        $this->deleteJson("{$url}/exceptions/{$exceptionId}")->assertStatus(409);
    }

    public function test_laytime_dispute_requires_reason_and_filters_work(): void
    {
        $id = $this->sailingVoyage();
        $call = $this->portCall($id);
        $calc = $this->postJson('/api/v1/laytime-calculations', [
            'port_call_id' => $call['id'], 'voyage_id' => $id, 'calculation_type' => 'discharge',
            'fixed_hours' => '72', 'laytime_commenced_at' => '2026-10-15T12:00', 'laytime_completed_at' => '2026-10-17T12:00',
        ])->assertCreated()->json('data');
        $url = "/api/v1/laytime-calculations/{$calc['id']}";
        $this->postJson("{$url}/calculate")->assertOk();
        $this->postJson("{$url}/submit")->assertOk();

        $this->as($this->commercial)->postJson("{$url}/dispute", [])->assertStatus(422);
        $this->as($this->commercial)->postJson("{$url}/dispute", ['reason' => 'NOR time disputed'])
            ->assertOk()->assertJsonPath('data.status', 'disputed');

        $list = $this->getJson("/api/v1/laytime-calculations?voyage_id={$id}&calculation_type=discharge")->assertOk()->json('data');
        $this->assertCount(1, $list);

        $this->as($this->charterer)->postJson('/api/v1/laytime-calculations', [])->assertForbidden();
    }

    /** The Laytime screen saves every field at once, sending null for anything left empty. */
    public function test_full_form_payload_with_nulls_is_accepted_and_clears_values(): void
    {
        $id = $this->sailingVoyage();
        $call = $this->portCall($id);
        $calc = $this->postJson('/api/v1/laytime-calculations', ['port_call_id' => $call['id'], 'voyage_id' => $id, 'calculation_type' => 'discharge', 'contract_id' => null])
            ->assertCreated()->json('data');

        $payload = [
            'lock_version' => $calc['lock_version'], 'fixed_hours' => '72', 'cargo_quantity' => null, 'rate_per_day' => null, 'rate_unit' => null, 'notice_time_hours' => null,
            'nor_tendered_at' => '2026-10-15T06:00', 'nor_accepted_at' => null, 'laytime_commenced_at' => '2026-10-15T12:00', 'laytime_completed_at' => '2026-10-18T12:00',
            'demurrage_rate_per_day' => '24000', 'despatch_rate_per_day' => null, 'currency' => 'USD', 'once_on_demurrage_rule' => 'always_on_demurrage', 'remarks' => null,
        ];
        $saved = $this->putJson("/api/v1/laytime-calculations/{$calc['id']}", $payload)->assertOk()->json('data');
        $this->assertSame('72.0000', $saved['fixed_hours']);
        $this->assertNull($saved['nor_accepted_at_local']);
        $this->assertSame('2026-10-18T12:00', $saved['laytime_completed_at_local']);

        $calculated = $this->postJson("/api/v1/laytime-calculations/{$calc['id']}/calculate")->assertOk()->json('data');
        $this->assertSame('72.0000', $calculated['used_hours']);   // 15 Oct 12:00 → 18 Oct 12:00 Dubai

        // Clearing a time again (empty field in the form) must be accepted.
        $cleared = $this->putJson("/api/v1/laytime-calculations/{$calc['id']}", [...$payload, 'lock_version' => $calculated['lock_version'], 'nor_tendered_at' => null, 'fixed_hours' => '60'])->assertOk()->json('data');
        $this->assertNull($cleared['nor_tendered_at_local']);
    }

    /** Found by the browser tests: a calculation created without a currency crashed when agreed (null currency reached the FX lookup). */
    public function test_currency_defaults_to_the_voyage_currency_so_agreeing_books_demurrage(): void
    {
        $id = $this->sailingVoyage();
        $call = $this->portCall($id);
        $calc = $this->postJson('/api/v1/laytime-calculations', ['port_call_id' => $call['id'], 'voyage_id' => $id, 'calculation_type' => 'load'])->assertCreated()->json('data');
        $this->assertSame('USD', $calc['currency']);

        $saved = $this->putJson("/api/v1/laytime-calculations/{$calc['id']}", [
            'lock_version' => $calc['lock_version'], 'fixed_hours' => '72', 'laytime_commenced_at' => '2026-10-15T06:00', 'laytime_completed_at' => '2026-10-19T12:00',
            'demurrage_rate_per_day' => '24000', 'currency' => null,
        ])->assertOk()->json('data');
        $this->assertSame('USD', $saved['currency']);   // explicitly clearing it falls back to the voyage currency

        $this->postJson("/api/v1/laytime-calculations/{$calc['id']}/calculate")->assertOk();
        $this->postJson("/api/v1/laytime-calculations/{$calc['id']}/submit")->assertOk();
        $this->as($this->commercial)->postJson("/api/v1/laytime-calculations/{$calc['id']}/agree")->assertOk()->assertJsonPath('data.status', 'agreed');
        $this->assertDatabaseHas('voyage_revenues', ['laytime_calculation_id' => $calc['id'], 'currency' => 'USD', 'status' => 'confirmed']);
    }

    public function test_an_emptied_rate_field_clears_the_stored_rate(): void
    {
        $id = $this->sailingVoyage();
        $call = $this->portCall($id);
        $calc = $this->postJson('/api/v1/laytime-calculations', ['port_call_id' => $call['id'], 'voyage_id' => $id, 'calculation_type' => 'load', 'demurrage_rate_per_day' => '24000'])->assertCreated()->json('data');
        $this->assertSame('24000.0000', $calc['demurrage_rate_per_day']);

        $cleared = $this->putJson("/api/v1/laytime-calculations/{$calc['id']}", ['lock_version' => $calc['lock_version'], 'demurrage_rate_per_day' => null])->assertOk()->json('data');
        $this->assertNull($cleared['demurrage_rate_per_day']);
    }
}
