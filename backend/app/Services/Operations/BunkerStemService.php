<?php

namespace App\Services\Operations;

use App\Exceptions\BusinessRuleException;
use App\Models\BunkerStem;
use App\Models\PortCall;
use App\Models\User;
use App\Models\Voyage;
use App\Services\ExchangeRateService;
use App\Services\SequenceService;
use App\Support\Decimal;
use App\Support\LocalTime;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Bunker stems (purchases).
 *   ordered → delivered (delivered quantity, time and BDN; amount = delivered_mt × price, FX to base at delivery)
 *   ordered → cancelled (reason); delivered → invoiced is set by payables (phase 9).
 * The delivery time is local to the port (port call / port), else the user's timezone.
 */
class BunkerStemService
{
    private const EDITABLE = ['supplier_company_id', 'port_id', 'port_call_id', 'fuel_type_id', 'ordered_on', 'ordered_mt', 'price_per_mt', 'currency',
        'invoice_reference', 'remarks'];

    public function __construct(private readonly SequenceService $sequences, private readonly ExchangeRateService $fx) {}

    /** @param array<string, mixed> $data */
    public function save(?BunkerStem $stem, array $data, User $actor): BunkerStem
    {
        return DB::transaction(function () use ($stem, $data, $actor) {
            if ($stem) {
                $stem = BunkerStem::query()->lockForUpdate()->findOrFail($stem->id);
                $stem->assertLockVersion(isset($data['lock_version']) ? (int) $data['lock_version'] : null);
                if (in_array($stem->status, ['invoiced', 'cancelled'], true)) {
                    throw new BusinessRuleException("A {$stem->status} stem cannot be edited.", 'stem_read_only');
                }
                if ($stem->status === 'delivered') {
                    // After delivery only the commercial references may change.
                    $data = Arr::only($data, ['invoice_reference', 'remarks', 'bdn_number', 'lock_version']);
                }
            }
            $s = $stem ?? new BunkerStem(['created_by' => $actor->id, 'vessel_id' => (int) $data['vessel_id'], 'voyage_id' => $data['voyage_id'] ?? null]);
            $s->fill(Arr::only($data, [...self::EDITABLE, 'bdn_number']));
            foreach (['ordered_mt', 'price_per_mt'] as $k) {
                if (isset($data[$k])) {
                    $s->setAttribute($k, Decimal::round((string) $data[$k], $k === 'ordered_mt' ? 3 : 4));
                }
            }
            $this->assertLinks($s);
            if ($s->status === 'ordered') {
                $s->total_amount = Decimal::round(Decimal::mul($s->ordered_mt, $s->price_per_mt), 2);
            }
            if (! $s->exists) {
                $s->stem_number = $this->sequences->next('bunker_stem', 'BS-'.now()->format('Y').'-');
            }
            $s->setAttribute('updated_by', $actor->id);
            $s->save();

            return $s;
        });
    }

    /** @param array{delivered_at: string, delivered_mt: string, bdn_number: string, lock_version: int} $data */
    public function deliver(BunkerStem $stem, array $data, User $actor): BunkerStem
    {
        return DB::transaction(function () use ($stem, $data, $actor) {
            $s = BunkerStem::query()->lockForUpdate()->findOrFail($stem->id);
            $s->assertLockVersion((int) $data['lock_version']);
            if ($s->status !== 'ordered') {
                throw new BusinessRuleException("A {$s->status} stem cannot be delivered.", 'invalid_status_transition');
            }
            $this->assertLinks($s);
            $tz = $s->portCall?->timezone() ?? (string) ($s->port?->getAttribute('timezone') ?? $actor->getAttribute('timezone') ?: 'UTC');
            $at = LocalTime::toUtc($data['delivered_at'], $tz, 'delivered_at');
            if ($at === null || $at->greaterThan(now()->addHour())) {
                throw new BusinessRuleException('The delivery time cannot be in the future.', 'validation_failed', ['delivered_at' => ['Future time.']], 422);
            }
            $qty = Decimal::round($data['delivered_mt'], 3);
            $total = Decimal::round(Decimal::mul($qty, $s->price_per_mt), 2);
            $base = (string) config('offshore.base_currency');
            $fx = $this->fx->resolve($s->currency, $base, $at);
            $s->fill([
                'status' => 'delivered', 'delivered_at' => $at, 'delivered_mt' => $qty, 'bdn_number' => $data['bdn_number'], 'total_amount' => $total,
                'fx_rate' => $fx['rate'], 'fx_method' => $fx['method'], 'base_amount' => Decimal::round(Decimal::mul($total, $fx['rate']), 2), 'updated_by' => $actor->id,
            ])->save();
            activity('bunker_stems')->performedOn($s)->causedBy($actor)->event('delivered')
                ->withProperties(['attributes' => ['delivered_mt' => $qty, 'total_amount' => $total, 'fx_rate' => $fx['rate']]])->log("Stem {$s->stem_number} delivered");

            return $s;
        });
    }

    public function cancel(BunkerStem $stem, User $actor, string $reason): BunkerStem
    {
        return DB::transaction(function () use ($stem, $actor, $reason) {
            $s = BunkerStem::query()->lockForUpdate()->findOrFail($stem->id);
            if ($s->status !== 'ordered') {
                throw new BusinessRuleException('Only an ordered stem can be cancelled.', 'invalid_status_transition');
            }
            $s->fill(['status' => 'cancelled', 'remarks' => trim(($s->getAttribute('remarks') ?? '')."\nCancelled: {$reason}"), 'updated_by' => $actor->id])->save();

            return $s;
        });
    }

    private function assertLinks(BunkerStem $s): void
    {
        $errors = [];
        $voyage = $s->voyage_id ? Voyage::query()->find($s->voyage_id) : null;
        if ($voyage) {
            if ($voyage->vessel_id !== $s->vessel_id) {
                $errors['voyage_id'] = ['The voyage belongs to another vessel.'];
            }
            if (! $voyage->isOperationallyOpen()) {
                throw new BusinessRuleException("The voyage is {$voyage->status}; bunker records are read-only.", 'voyage_read_only');
            }
        }
        if ($s->port_call_id) {
            $call = PortCall::query()->find($s->port_call_id);
            if (! $call || $call->voyage_id !== $s->voyage_id) {
                $errors['port_call_id'] = ['The port call does not belong to the stem voyage.'];
            } else {
                $s->port_id ??= $call->port_id;
            }
        }
        if ($errors !== []) {
            throw new BusinessRuleException('The stem links are inconsistent.', 'validation_failed', $errors, 422);
        }
    }
}
