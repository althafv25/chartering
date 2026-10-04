<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselNameHistory;
use App\Models\VesselType;
use App\Support\Decimal;
use App\Support\ListQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VesselService
{
    public const RELATIONS = ['vesselType', 'owner', 'manager', 'commercialManager', 'technicalManager'];

    /** @param array<string, mixed> $f */
    public function paginate(array $f, int $perPage): LengthAwarePaginator
    {
        $query = Vessel::query()->with(['vesselType', 'owner']);

        if ($term = trim((string) ($f['search'] ?? ''))) {
            $like = ListQuery::like($term);
            $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('code', 'like', $like)
                ->orWhere('imo_number', 'like', $like)->orWhere('mmsi', 'like', $like)->orWhere('call_sign', 'like', $like)
                ->orWhereHas('nameHistory', fn ($h) => $h->where('name', 'like', $like)));
        }
        foreach (['vessel_type_id', 'status', 'commercial_status', 'operational_status', 'owner_company_id', 'ownership_type'] as $col) {
            if (! empty($f[$col])) {
                $query->where($col, $f[$col]);
            }
        }

        return ListQuery::sort($query, $f['sort'] ?? null, ['name', 'code', 'year_built', 'dwt_mt', 'created_at'], 'name')->paginate($perPage);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): Vessel
    {
        $data['custom_attributes'] = $this->validateCustomAttributes((int) $data['vessel_type_id'], $data['custom_attributes'] ?? []);

        return DB::transaction(fn () => Vessel::query()->create([
            ...$this->fields($data),
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ])->load(self::RELATIONS));
    }

    /** @param array<string, mixed> $data */
    public function update(Vessel $vessel, array $data, User $actor): Vessel
    {
        return DB::transaction(function () use ($vessel, $data, $actor) {
            $locked = Vessel::query()->lockForUpdate()->findOrFail($vessel->id);
            $locked->assertLockVersion(isset($data['lock_version']) ? (int) $data['lock_version'] : null);

            $typeId = (int) ($data['vessel_type_id'] ?? $locked->vessel_type_id);
            if (array_key_exists('custom_attributes', $data) || $typeId !== $locked->vessel_type_id) {
                $data['custom_attributes'] = $this->validateCustomAttributes($typeId, $data['custom_attributes'] ?? $locked->custom_attributes ?? []);
            }

            if (isset($data['name']) && $data['name'] !== $locked->name) {
                VesselNameHistory::query()->create([
                    'vessel_id' => $locked->id, 'name' => $locked->name, 'valid_to' => now()->toDateString(), 'changed_by' => $actor->id,
                ]);
            }

            $locked->fill([...$this->fields($data), 'updated_by' => $actor->id])->save();

            return $locked->load(self::RELATIONS);
        });
    }

    public function delete(Vessel $vessel): void
    {
        // Later phases: block when voyages / contracts reference the vessel.
        $vessel->delete();
    }

    /**
     * Validates type-specific attributes against vessel_types.attribute_schema.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>|null
     */
    public function validateCustomAttributes(int $vesselTypeId, array $attributes): ?array
    {
        $schema = collect(VesselType::query()->findOrFail($vesselTypeId)->attribute_schema ?? [])->keyBy('key');
        $errors = [];
        $clean = [];

        foreach ($attributes as $key => $value) {
            $def = $schema->get($key);
            if (! $def) {
                $errors["custom_attributes.{$key}"] = ["“{$key}” is not defined for this vessel type."];

                continue;
            }
            if ($value === null || $value === '') {
                continue;
            }
            $label = $def['label'];
            switch ($def['data_type']) {
                case 'decimal':
                    if (! is_numeric($value) || ! preg_match('/^-?\d{1,12}(\.\d{1,4})?$/', (string) $value)) {
                        $errors["custom_attributes.{$key}"] = ["{$label} must be a number with up to 4 decimals."];
                    } else {
                        $clean[$key] = Decimal::of((string) $value);
                    }
                    break;
                case 'integer':
                    if (filter_var($value, FILTER_VALIDATE_INT) === false) {
                        $errors["custom_attributes.{$key}"] = ["{$label} must be a whole number."];
                    } else {
                        $clean[$key] = (int) $value;
                    }
                    break;
                case 'bool':
                    $clean[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                    break;
                case 'select':
                    if (! in_array($value, $def['options'] ?? [], true)) {
                        $errors["custom_attributes.{$key}"] = ["{$label} has an invalid option."];
                    } else {
                        $clean[$key] = $value;
                    }
                    break;
                default:
                    $clean[$key] = mb_substr((string) $value, 0, 255);
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $clean ?: null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fields(array $data): array
    {
        $fields = Arr::except($data, ['lock_version', 'commercial_status', 'operational_status', 'created_by', 'updated_by']);
        if (isset($fields['code'])) {
            $fields['code'] = strtoupper($fields['code']);
        }

        return array_intersect_key($fields, array_flip((new Vessel)->getFillable()));
    }

    public function assertTypeChangeAllowed(Vessel $vessel, int $newTypeId): void
    {
        if ($vessel->vessel_type_id !== $newTypeId && ! empty($vessel->custom_attributes)) {
            throw new BusinessRuleException('Clear the type-specific fields before changing the vessel type.', 'vessel_type_change_blocked');
        }
    }
}
