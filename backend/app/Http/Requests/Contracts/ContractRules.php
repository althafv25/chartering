<?php

namespace App\Http\Requests\Contracts;

use App\Models\ContractRate;
use App\Support\Rules;
use Illuminate\Validation\Rule;

/** Shared rule fragments for contract header, rates and clauses. */
final class ContractRules
{
    /** @return array<string, list<mixed>> */
    public static function rates(string $prefix = 'rates', bool $required = true): array
    {
        return [
            $prefix => [$required ? 'present' : 'sometimes', 'array', 'max:100'],
            "{$prefix}.*.rate_type" => ['required', Rule::in(ContractRate::TYPES)],
            "{$prefix}.*.offshore_activity_type_id" => ['nullable', 'integer', Rule::exists('offshore_activity_types', 'id')],
            "{$prefix}.*.description" => ['nullable', 'string', 'max:200'],
            "{$prefix}.*.amount" => Rules::decimal(14, 4, required: true),
            "{$prefix}.*.currency" => Rules::currency(),
            "{$prefix}.*.unit" => ['nullable', Rule::in(ContractRate::UNITS)],
            "{$prefix}.*.effective_from" => ['nullable', 'date'],
            "{$prefix}.*.effective_to" => ['nullable', 'date'],
            "{$prefix}.*.notes" => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, list<mixed>> */
    public static function clauses(string $prefix = 'clauses', bool $required = true): array
    {
        return [
            $prefix => [$required ? 'present' : 'sometimes', 'array', 'max:200'],
            "{$prefix}.*.clause_ref" => ['nullable', 'string', 'max:30'],
            "{$prefix}.*.title" => ['required', 'string', 'max:200'],
            "{$prefix}.*.body" => ['required', 'string', 'max:20000'],
        ];
    }

    /** @return array<string, list<mixed>> */
    public static function commissions(string $prefix = 'commissions'): array
    {
        $pct = [...Rules::decimal(3, 4), 'lte:100'];

        return [
            $prefix => ['sometimes', 'nullable', 'array'],
            "{$prefix}.address_pct" => $pct,
            "{$prefix}.brokerage_pct" => $pct,
            "{$prefix}.other_pct" => $pct,
            "{$prefix}.broker_company_id" => ['nullable', 'integer', Rule::exists('companies', 'id')],
        ];
    }
}
