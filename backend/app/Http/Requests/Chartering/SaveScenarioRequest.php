<?php

namespace App\Http\Requests\Chartering;

use App\Domain\Estimation\Input\ConsumptionInput;
use App\Domain\Estimation\Input\CostItemInput;
use App\Domain\Estimation\Input\RevenueItemInput;
use App\Support\Rules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Scenario input snapshot. Missing values are allowed (scenario becomes "incomplete"); malformed values are not. */
class SaveScenarioRequest extends FormRequest
{
    use NormalizesDecimals;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', $this->route('estimation'));
    }

    public function rules(): array
    {
        $ccy = ['nullable', 'string', 'size:3', Rule::exists('currencies', 'code')];
        $fx = Rules::decimal(10, 8);
        $acct = ['nullable', Rule::in(['owner', 'charterer'])];
        $days = Rules::decimal(6, 6);
        $pct = [...Rules::decimal(3, 4), 'lte:100'];
        $fuel = ['required', 'integer', Rule::exists('fuel_types', 'id')];

        return [
            'lock_version' => ['required', 'integer'],
            'name' => ['sometimes', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'inputs' => ['sometimes', 'array'],
            'inputs.sea_margin_pct' => $pct,
            'inputs.eca_fuel_type_id' => ['nullable', 'integer', Rule::exists('fuel_types', 'id')],
            'inputs.legs' => ['present_with:inputs', 'array', 'max:60'],
            'inputs.legs.*.label' => ['nullable', 'string', 'max:200'],
            'inputs.legs.*.from' => ['nullable', 'array'],
            'inputs.legs.*.to' => ['nullable', 'array'],
            'inputs.legs.*.condition' => ['required', Rule::in(['laden', 'ballast'])],
            'inputs.legs.*.distance_nm' => Rules::decimal(8, 2),
            'inputs.legs.*.eca_distance_nm' => Rules::decimal(8, 2),
            'inputs.legs.*.speed_kn' => Rules::decimal(3, 2),
            'inputs.legs.*.sea_margin_pct' => $pct,
            'inputs.legs.*.distance_source' => ['nullable', 'string', 'max:60'],
            'inputs.calls' => ['present_with:inputs', 'array', 'max:60'],
            'inputs.calls.*.label' => ['nullable', 'string', 'max:200'],
            'inputs.calls.*.point' => ['nullable', 'array'],
            'inputs.calls.*.purpose' => ['nullable', 'string', 'max:30'],
            'inputs.calls.*.kind' => ['required', Rule::in(['port', 'offshore'])],
            'inputs.calls.*.working_days' => $days,
            'inputs.calls.*.idle_days' => $days,
            'inputs.calls.*.waiting_days' => $days,
            'inputs.calls.*.dp_days' => $days,
            'inputs.calls.*.standby_days' => $days,
            'inputs.calls.*.port_cost' => ['nullable', 'array'],
            'inputs.calls.*.port_cost.amount' => Rules::decimal(16, 2),
            'inputs.calls.*.port_cost.currency' => $ccy,
            'inputs.calls.*.port_cost.fx_rate' => $fx,
            'inputs.calls.*.port_cost.account' => $acct,
            'inputs.calls.*.agency_cost' => ['nullable', 'array'],
            'inputs.calls.*.agency_cost.amount' => Rules::decimal(16, 2),
            'inputs.calls.*.agency_cost.currency' => $ccy,
            'inputs.calls.*.agency_cost.fx_rate' => $fx,
            'inputs.calls.*.agency_cost.account' => $acct,
            'inputs.consumption' => ['present_with:inputs', 'array', 'max:200'],
            'inputs.consumption.*.mode' => ['required', Rule::in(ConsumptionInput::MODES)],
            'inputs.consumption.*.speed_kn' => Rules::decimal(3, 2),
            'inputs.consumption.*.fuel_type_id' => $fuel,
            'inputs.consumption.*.mt_per_day' => Rules::decimal(7, 3, required: true),
            'inputs.fuel_prices' => ['present_with:inputs', 'array', 'max:30'],
            'inputs.fuel_prices.*.fuel_type_id' => [...$fuel, 'distinct'],
            'inputs.fuel_prices.*.price_per_mt' => Rules::decimal(14, 4),
            'inputs.fuel_prices.*.currency' => $ccy,
            'inputs.fuel_prices.*.fx_rate' => $fx,
            'inputs.fuel_prices.*.account' => $acct,
            'inputs.revenue_items' => ['present_with:inputs', 'array', 'max:50'],
            'inputs.revenue_items.*.key' => ['nullable', 'string', 'max:40'],
            'inputs.revenue_items.*.revenue_category_id' => ['required', 'integer', Rule::exists('revenue_categories', 'id')],
            'inputs.revenue_items.*.description' => ['nullable', 'string', 'max:150'],
            'inputs.revenue_items.*.basis' => ['required', Rule::in(RevenueItemInput::BASES)],
            'inputs.revenue_items.*.quantity' => Rules::decimal(12, 4),
            'inputs.revenue_items.*.rate' => Rules::decimal(14, 4),
            'inputs.revenue_items.*.currency' => $ccy,
            'inputs.revenue_items.*.fx_rate' => $fx,
            'inputs.revenue_items.*.commissionable' => ['sometimes', 'boolean'],
            'inputs.revenue_items.*.address_pct' => $pct,
            'inputs.revenue_items.*.brokerage_pct' => $pct,
            'inputs.revenue_items.*.other_pct' => $pct,
            'inputs.revenue_items.*.primary' => ['sometimes', 'boolean'],
            'inputs.cost_items' => ['present_with:inputs', 'array', 'max:100'],
            'inputs.cost_items.*.key' => ['nullable', 'string', 'max:40'],
            'inputs.cost_items.*.expense_category_id' => ['required', 'integer', Rule::exists('expense_categories', 'id')],
            'inputs.cost_items.*.description' => ['nullable', 'string', 'max:150'],
            'inputs.cost_items.*.basis' => ['required', Rule::in(CostItemInput::BASES)],
            'inputs.cost_items.*.quantity' => Rules::decimal(12, 4),
            'inputs.cost_items.*.rate' => Rules::decimal(14, 4),
            'inputs.cost_items.*.currency' => $ccy,
            'inputs.cost_items.*.fx_rate' => $fx,
            'inputs.cost_items.*.account' => $acct,
        ];
    }
}
