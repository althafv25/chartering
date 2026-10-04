<?php

namespace Tests\Feature\Operations;

use App\Enums\UserRole;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\User;
use App\Services\SettingsService;

class BunkerTest extends OperationsTestCase
{
    private User $ops2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ops2 = $this->userWithRole(UserRole::Operations);
    }

    public function test_stem_order_delivery_amounts_and_fx(): void
    {
        $id = $this->sailingVoyage();
        $call = $this->portCall($id);
        $stem = $this->postJson('/api/v1/bunker-stems', ['vessel_id' => $this->vessel->id, 'voyage_id' => $id, 'port_call_id' => $call['id'], 'fuel_type_id' => $this->mgo,
            'ordered_on' => '2026-10-14', 'ordered_mt' => '40', 'price_per_mt' => '650.5', 'currency' => 'USD'])->assertCreated()->json('data');
        $this->assertSame('26020.00', $stem['total_amount']);
        $this->assertSame('ordered', $stem['status']);
        $this->assertSame($this->ports[0], $stem['port_id']); // taken from the port call
        $this->assertMatchesRegularExpression('/^BS-2026-\d{5}$/', $stem['stem_number']);

        $url = "/api/v1/bunker-stems/{$stem['id']}";
        $this->postJson("{$url}/deliver", ['lock_version' => $stem['lock_version'], 'delivered_at' => '2026-10-25T08:00', 'delivered_mt' => '39.5', 'bdn_number' => 'BDN-1'])->assertStatus(422);
        $d = $this->postJson("{$url}/deliver", ['lock_version' => $stem['lock_version'], 'delivered_at' => '2026-10-15T14:00', 'delivered_mt' => '39.5', 'bdn_number' => 'BDN-1'])
            ->assertOk()->json('data');
        $this->assertSame(['delivered', '39.500', '25694.75', '25694.75', 'identity'], [$d['status'], $d['delivered_mt'], $d['total_amount'], $d['base_amount'], $d['fx_method']]);
        $this->assertSame('2026-10-15T10:00:00+00:00', $d['delivered_at']); // Jebel Ali local → UTC
        $this->putJson($url, ['lock_version' => $d['lock_version'], 'price_per_mt' => '1', 'invoice_reference' => 'INV-77'])->assertOk()
            ->assertJsonPath('data.price_per_mt', '650.5000')->assertJsonPath('data.invoice_reference', 'INV-77');
        $this->postJson("{$url}/cancel", ['reason' => 'late'])->assertStatus(409);

        // Foreign currency: FX snapshot at delivery, refused without a rate.
        Currency::query()->firstOrCreate(['code' => 'AED'], ['name' => 'UAE Dirham', 'decimals' => 2, 'status' => 'active']);
        $aed = $this->postJson('/api/v1/bunker-stems', ['vessel_id' => $this->vessel->id, 'voyage_id' => $id, 'fuel_type_id' => $this->mgo, 'ordered_on' => '2026-10-14',
            'ordered_mt' => '10', 'price_per_mt' => '2400', 'currency' => 'AED'])->assertCreated()->json('data');
        $body = ['lock_version' => $aed['lock_version'], 'delivered_at' => '2026-10-16T09:00', 'delivered_mt' => '10', 'bdn_number' => 'BDN-2'];
        $this->postJson("/api/v1/bunker-stems/{$aed['id']}/deliver", $body)->assertStatus(422)->assertJsonPath('error_code', 'exchange_rate_missing');
        ExchangeRate::query()->create(['base_currency' => 'AED', 'quote_currency' => 'USD', 'rate' => '0.27229408', 'rate_date' => '2026-10-15', 'source' => 'manual']);
        $this->postJson("/api/v1/bunker-stems/{$aed['id']}/deliver", $body)->assertOk()->assertJsonPath('data.total_amount', '24000.00')->assertJsonPath('data.base_amount', '6535.06');
    }

    public function test_stem_links_cancel_and_permissions(): void
    {
        $id = $this->voyage();
        $other = $this->sailingVoyage();
        $otherCall = $this->portCall($other);
        $this->postJson('/api/v1/bunker-stems', ['vessel_id' => $this->vessel->id, 'voyage_id' => $id, 'port_call_id' => $otherCall['id'], 'fuel_type_id' => $this->mgo,
            'ordered_on' => '2026-10-14', 'ordered_mt' => '10', 'price_per_mt' => '600', 'currency' => 'USD'])->assertStatus(422);
        $s = $this->postJson('/api/v1/bunker-stems', ['vessel_id' => $this->vessel->id, 'voyage_id' => $id, 'fuel_type_id' => $this->mgo,
            'ordered_on' => '2026-10-14', 'ordered_mt' => '10', 'price_per_mt' => '600', 'currency' => 'USD'])->assertCreated()->json('data');
        $this->postJson("/api/v1/bunker-stems/{$s['id']}/cancel", ['reason' => 'Supplier failed'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->putJson("/api/v1/bunker-stems/{$s['id']}", ['lock_version' => $s['lock_version'] + 1, 'remarks' => 'x'])->assertStatus(409);

        $this->as($this->charterer)->getJson('/api/v1/bunker-stems')->assertOk();
        $this->postJson('/api/v1/bunker-stems', [])->assertForbidden();
    }

    public function test_rob_ledger_continuity_received_check_and_consumption_flag(): void
    {
        $id = $this->sailingVoyage();
        $report = function (string $at, string $rob, string $consumed, string $received = '0') use ($id) {
            $r = $this->as($this->ops)->postJson('/api/v1/captain-reports', ['vessel_id' => $this->vessel->id, 'voyage_id' => $id, 'report_type' => 'noon', 'reported_at' => $at,
                'fuel_lines' => [['fuel_type_id' => $this->mgo, 'rob_mt' => $rob, 'consumed_mt' => $consumed, 'received_mt' => $received]]])->assertCreated()->json('data');
            $this->postJson("/api/v1/captain-reports/{$r['id']}/submit")->assertOk();
            $this->as($this->ops2)->postJson("/api/v1/captain-reports/{$r['id']}/verify")->assertOk();
        };
        $report('2026-10-15T08:00:00Z', '180', '0');
        $report('2026-10-16T08:00:00Z', '171', '9');
        $report('2026-10-17T08:00:00Z', '200', '10', '40'); // 171 + 40 − 10 = 201 ≠ 200

        $stem = $this->as($this->ops)->postJson('/api/v1/bunker-stems', ['vessel_id' => $this->vessel->id, 'voyage_id' => $id, 'fuel_type_id' => $this->mgo,
            'ordered_on' => '2026-10-15', 'ordered_mt' => '40', 'price_per_mt' => '650', 'currency' => 'USD'])->json('data');
        $this->postJson("/api/v1/bunker-stems/{$stem['id']}/deliver", ['lock_version' => $stem['lock_version'], 'delivered_at' => '2026-10-17T00:00', 'delivered_mt' => '38', 'bdn_number' => 'B1'])->assertOk();

        app(SettingsService::class)->update(['bunker.discrepancy_threshold_pct' => '0'], $this->manager);
        $mgo = $this->getJson("/api/v1/voyages/{$id}/rob-ledger")->assertOk()->json('data.fuels.0');
        $this->assertSame('MGO', $mgo['fuel_code']);
        [$first, $second, $third] = $mgo['rows'];
        $this->assertNull($first['opening_mt']);
        $this->assertSame(['180.000', '171.000', false], [$second['opening_mt'], $second['closing_mt'], $second['rob_discontinuity']]);
        $this->assertSame(['201.000', '-1.000', true], [$third['closing_mt'], $third['rob_difference_mt'], $third['rob_discontinuity']]);
        $this->assertSame(['38.000', true], [$third['stems_delivered_mt'], $third['received_mismatch']]);
        $this->assertNotNull($second['estimated_consumed_mt']);
        $this->assertTrue($second['flagged']);
        $this->assertSame(['received_mt' => '40.000', 'consumed_mt' => '19.000', 'stems_mt' => '38.000'], array_intersect_key($mgo['totals'], array_flip(['received_mt', 'consumed_mt', 'stems_mt'])));
        $this->assertSame(1, $mgo['flags']['rob_discontinuity']);

        app(SettingsService::class)->update(['bunker.discrepancy_threshold_pct' => '100'], $this->manager);
        $ledger = $this->getJson("/api/v1/voyages/{$id}/rob-ledger")->json('data');
        $expected = collect($ledger['fuels'][0]['rows'])->filter(fn ($r) => $r['variance_pct'] !== null && bccomp(ltrim($r['variance_pct'], '-'), '100', 2) > 0)->count();
        $this->assertSame('100', $ledger['threshold_pct']);
        $this->assertSame($expected, $ledger['fuels'][0]['flags']['consumption']);
    }
}
