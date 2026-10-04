<?php

namespace App\Http\Requests\Chartering;

use App\Models\Enquiry;
use App\Models\EnquiryPort;
use App\Support\Rules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveEnquiryRequest extends FormRequest
{
    use NormalizesDecimals;

    public function authorize(): bool
    {
        $enquiry = $this->route('enquiry');

        return $enquiry instanceof Enquiry ? (bool) $this->user()?->can('update', $enquiry) : (bool) $this->user()?->can('create', Enquiry::class);
    }

    public function rules(): array
    {
        $enquiry = $this->route('enquiry');
        $req = $enquiry ? 'sometimes' : 'required';
        $company = ['nullable', 'integer', Rule::exists('companies', 'id')->whereNull('deleted_at')];

        return [
            'lock_version' => [$enquiry ? 'required' : 'prohibited', 'integer'],
            'received_at' => ['sometimes', 'date'],
            'source' => ['sometimes', Rule::in(['direct', 'broker', 'tender'])],
            'business_type' => [$req, Rule::in(Enquiry::BUSINESS_TYPES)],
            'charterer_company_id' => $company,
            'broker_company_id' => $company,
            'cargo_type_id' => ['nullable', 'integer', Rule::exists('cargo_types', 'id')],
            'cargo_description' => ['nullable', 'string', 'max:255'],
            'quantity' => Rules::decimal(11, 3),
            'quantity_unit' => ['nullable', Rule::in(['mt', 'm3', 'bbl', 'units', 'days'])],
            'quantity_tolerance_pct' => [...Rules::decimal(3, 4), 'lte:100'],
            'offshore_location_id' => ['nullable', 'integer', Rule::exists('offshore_locations', 'id')->whereNull('deleted_at')],
            'laycan_from' => ['nullable', 'date'],
            'laycan_to' => ['nullable', 'date', 'after_or_equal:laycan_from'],
            'period_days' => Rules::decimal(8, 2),
            'rate_idea' => Rules::decimal(14, 4),
            'rate_basis' => ['nullable', Rule::in(['per_mt', 'per_day', 'per_hour', 'lump_sum'])],
            'currency' => Rules::currency(),
            'commission_terms' => ['nullable', 'string', 'max:2000'],
            'terms' => ['nullable', 'string', 'max:10000'],
            'remarks' => ['nullable', 'string', 'max:5000'],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'ports' => ['sometimes', 'array', 'max:30'],
            'ports.*.port_id' => ['nullable', 'integer', 'required_without:ports.*.offshore_location_id', 'prohibits:ports.*.offshore_location_id', Rule::exists('ports', 'id')->whereNull('deleted_at')],
            'ports.*.offshore_location_id' => ['nullable', 'integer', Rule::exists('offshore_locations', 'id')->whereNull('deleted_at')],
            'ports.*.purpose' => ['required', Rule::in(EnquiryPort::PURPOSES)],
            'ports.*.notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}
