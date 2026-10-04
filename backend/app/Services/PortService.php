<?php

namespace App\Services;

use App\Enums\CompanyRole;
use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Port;
use App\Models\User;
use App\Support\ListQuery;
use App\Support\NameNormalizer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class PortService
{
    private const FIELDS = ['name', 'unlocode', 'country', 'region', 'latitude', 'longitude', 'timezone', 'max_draft_m', 'max_loa_m', 'max_beam_m', 'restrictions', 'notes', 'status'];

    /** @param array<string, mixed> $f */
    public function paginate(array $f, int $perPage): LengthAwarePaginator
    {
        $query = Port::query()->withCount('agents');

        if ($term = trim((string) ($f['search'] ?? ''))) {
            $like = ListQuery::like($term);
            $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('unlocode', 'like', $like)
                ->orWhere('normalized_name', 'like', ListQuery::like(NameNormalizer::normalize($term))));
        }
        foreach (['country', 'region', 'status'] as $col) {
            if (! empty($f[$col])) {
                $query->where($col, $f[$col]);
            }
        }

        return ListQuery::sort($query, $f['sort'] ?? null, ['name', 'unlocode', 'country', 'created_at'], 'name')->paginate($perPage);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): Port
    {
        return Port::query()->create([...$this->fields($data), 'created_by' => $actor->id, 'updated_by' => $actor->id]);
    }

    /** @param array<string, mixed> $data */
    public function update(Port $port, array $data, User $actor): Port
    {
        $port->fill([...$this->fields($data), 'updated_by' => $actor->id])->save();

        return $port;
    }

    public function addAgent(Port $port, int $companyId, bool $isDefault, ?string $remarks): void
    {
        $company = Company::query()->with('roles')->findOrFail($companyId);
        if (! in_array(CompanyRole::Agent->value, $company->roleNames(), true)) {
            throw new BusinessRuleException("{$company->legal_name} does not have the Agent role in the address book.", 'company_not_agent', status: 422);
        }

        DB::transaction(function () use ($port, $companyId, $isDefault, $remarks) {
            if ($isDefault) {
                DB::table('port_agents')->where('port_id', $port->id)->update(['is_default' => false]);
            }
            $port->agents()->syncWithoutDetaching([$companyId => ['is_default' => $isDefault, 'remarks' => $remarks]]);
        });
    }

    public function removeAgent(Port $port, int $companyId): void
    {
        $port->agents()->detach($companyId);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fields(array $data): array
    {
        $fields = Arr::only($data, self::FIELDS);
        if (array_key_exists('unlocode', $fields) && $fields['unlocode'] !== null) {
            $fields['unlocode'] = strtoupper(str_replace(' ', '', $fields['unlocode']));
        }
        if (isset($fields['name'])) {
            $fields['normalized_name'] = NameNormalizer::normalize($fields['name']);
        }

        return $fields;
    }
}
