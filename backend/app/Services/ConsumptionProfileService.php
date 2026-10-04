<?php

namespace App\Services;

use App\Enums\ConsumptionMode;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselConsumptionProfile;
use App\Support\Decimal;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Vessel consumption profiles (MT/day by mode × speed × fuel).
 * Rules:
 *  - profiles with the same name must not overlap in time (versioning)
 *  - sea modes need speed > 0; other modes use speed 0
 *  - one (mode, speed, fuel) combination per profile
 *  - exactly one default profile per vessel (the first one becomes default)
 */
class ConsumptionProfileService
{
    /** @param array<string, mixed> $data */
    public function create(Vessel $vessel, array $data, User $actor): VesselConsumptionProfile
    {
        return DB::transaction(function () use ($vessel, $data, $actor) {
            Vessel::query()->lockForUpdate()->findOrFail($vessel->id);
            $this->assertNoOverlap($vessel->id, $data['name'], $data['effective_from'], $data['effective_to'] ?? null);
            $rates = $this->normalizeRates($data['rates']);

            $isFirst = ! VesselConsumptionProfile::query()->where('vessel_id', $vessel->id)->exists();
            $profile = VesselConsumptionProfile::query()->create([
                ...Arr::only($data, ['name', 'source', 'effective_from', 'effective_to', 'remarks']),
                'vessel_id' => $vessel->id,
                'is_default' => $isFirst || ! empty($data['is_default']),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            $profile->rates()->createMany($rates);

            if ($profile->is_default) {
                $this->makeDefault($profile);
            }

            return $profile->load('rates.fuelType');
        });
    }

    /** @param array<string, mixed> $data */
    public function update(VesselConsumptionProfile $profile, array $data, User $actor): VesselConsumptionProfile
    {
        return DB::transaction(function () use ($profile, $data, $actor) {
            Vessel::query()->lockForUpdate()->findOrFail($profile->vessel_id);
            $this->assertNoOverlap(
                $profile->vessel_id,
                $data['name'] ?? $profile->name,
                $data['effective_from'] ?? $profile->effective_from->toDateString(),
                array_key_exists('effective_to', $data) ? $data['effective_to'] : $profile->effective_to?->toDateString(),
                $profile->id,
            );

            $profile->fill([...Arr::only($data, ['name', 'source', 'effective_from', 'effective_to', 'remarks']), 'updated_by' => $actor->id])->save();

            if (array_key_exists('rates', $data)) {
                $before = $this->rateSnapshot($profile);
                $profile->rates()->delete();
                $profile->rates()->createMany($this->normalizeRates($data['rates']));
                $after = $this->rateSnapshot($profile->fresh());
                if ($before !== $after) {
                    activity('vessel_consumption_profiles')->performedOn($profile)->causedBy($actor)->event('rates_changed')
                        ->withProperties(['old' => ['rates' => $before], 'attributes' => ['rates' => $after]])
                        ->log('Consumption rates changed');
                }
            }

            if (! empty($data['is_default'])) {
                $this->makeDefault($profile);
            }

            return $profile->fresh(['rates.fuelType']);
        });
    }

    public function delete(VesselConsumptionProfile $profile): void
    {
        DB::transaction(function () use ($profile) {
            $wasDefault = $profile->is_default;
            $vesselId = $profile->vessel_id;
            $profile->delete();

            if ($wasDefault) {
                $next = VesselConsumptionProfile::query()->where('vessel_id', $vesselId)->orderByDesc('effective_from')->first();
                $next?->forceFill(['is_default' => true])->save();
            }
        });
    }

    private function makeDefault(VesselConsumptionProfile $profile): void
    {
        VesselConsumptionProfile::query()->where('vessel_id', $profile->vessel_id)->whereKeyNot($profile->id)
            ->where('is_default', true)->update(['is_default' => false]);
        if (! $profile->is_default) {
            $profile->forceFill(['is_default' => true])->save();
        }
    }

    private function assertNoOverlap(int $vesselId, string $name, string $from, ?string $to, ?int $ignoreId = null): void
    {
        if ($to !== null && $to < $from) {
            throw ValidationException::withMessages(['effective_to' => ['The end date must be on or after the start date.']]);
        }

        $overlap = VesselConsumptionProfile::query()
            ->where('vessel_id', $vesselId)->where('name', $name)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('effective_from', '<=', $to))
            ->exists();

        if ($overlap) {
            throw new BusinessRuleException(
                "Another “{$name}” profile is already effective in this period. Close it (set an end date) or use a different name.",
                'profile_period_overlap',
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rates
     * @return list<array<string, mixed>>
     */
    private function normalizeRates(array $rates): array
    {
        $seen = [];
        $out = [];
        $errors = [];

        foreach ($rates as $i => $r) {
            $mode = ConsumptionMode::from($r['mode']);
            $speed = Decimal::round((string) ($r['speed_kn'] ?? '0'), 2);

            if ($mode->isSpeedDependent() && Decimal::cmp($speed, '0') <= 0) {
                $errors["rates.{$i}.speed_kn"] = ['Sea modes require a speed greater than 0.'];
            }
            if (! $mode->isSpeedDependent()) {
                $speed = '0.00';
            }

            $key = "{$mode->value}|{$speed}|{$r['fuel_type_id']}";
            if (isset($seen[$key])) {
                $errors["rates.{$i}.mode"] = ['Duplicate mode / speed / fuel combination.'];
            }
            $seen[$key] = true;

            $out[] = [
                'mode' => $mode->value,
                'speed_kn' => $speed,
                'fuel_type_id' => (int) $r['fuel_type_id'],
                'consumption_mt_per_day' => Decimal::round((string) $r['consumption_mt_per_day'], 3),
            ];
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    /** @return list<string> */
    private function rateSnapshot(VesselConsumptionProfile $profile): array
    {
        return $profile->rates()->get()
            ->map(fn ($r) => "{$r->mode}@{$r->speed_kn}kn fuel#{$r->fuel_type_id}={$r->consumption_mt_per_day}")
            ->sort()->values()->all();
    }
}
