<?php

namespace App\Http\Requests\Chartering;

use App\Models\Offer;
use App\Support\Rules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Offer creation (with revision 1) and revision create/update. */
class SaveRevisionRequest extends FormRequest
{
    use NormalizesDecimals;

    public function authorize(): bool
    {
        $offer = $this->route('offer');

        return $offer ? (bool) $this->user()?->can('update', $offer) : (bool) $this->user()?->can('create', Offer::class);
    }

    public function rules(): array
    {
        $creatingOffer = ! $this->route('offer');
        $pct = [...Rules::decimal(3, 4), 'lte:100'];

        return [
            'enquiry_id' => [$creatingOffer ? 'required' : 'prohibited', 'integer', Rule::exists('enquiries', 'id')->whereNull('deleted_at')],
            'vessel_id' => [$creatingOffer ? 'required' : 'prohibited', 'integer', Rule::exists('vessels', 'id')->whereNull('deleted_at')],
            'counterparty_company_id' => ['nullable', 'integer', Rule::exists('companies', 'id')->whereNull('deleted_at')],
            'direction' => ['sometimes', Rule::in(['outbound', 'inbound'])],
            'estimation_scenario_id' => ['nullable', 'integer', Rule::exists('estimation_scenarios', 'id')],
            'rate' => [...Rules::decimal(14, 4), 'sometimes'],
            'rate_basis' => ['sometimes', Rule::in(['per_mt', 'per_day', 'per_hour', 'lump_sum'])],
            'currency' => ['sometimes', ...Rules::currency()],
            'quantity' => Rules::decimal(11, 3),
            'quantity_unit' => ['nullable', Rule::in(['mt', 'm3', 'bbl', 'units', 'days'])],
            'laycan_from' => ['nullable', 'date'],
            'laycan_to' => ['nullable', 'date', 'after_or_equal:laycan_from'],
            'period_days' => Rules::decimal(8, 2),
            'ports' => ['sometimes', 'array', 'max:30'],
            'ports.*.type' => ['required', Rule::in(['port', 'location'])],
            'ports.*.id' => ['required', 'integer'],
            'ports.*.label' => ['required', 'string', 'max:200'],
            'ports.*.purpose' => ['nullable', 'string', 'max:30'],
            'ports.*.sequence' => ['nullable', 'integer'],
            'commissions' => ['sometimes', 'array'],
            'commissions.address_pct' => $pct,
            'commissions.brokerage_pct' => $pct,
            'commissions.other_pct' => $pct,
            'commissions.broker_company_id' => ['nullable', 'integer', Rule::exists('companies', 'id')],
            'terms' => ['nullable', 'string', 'max:20000'],
            'valid_until' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
