<?php

namespace App\Http\Requests\Contracts;

use App\Http\Requests\Chartering\NormalizesDecimals;
use App\Models\Contract;
use App\Support\Rules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveContractRequest extends FormRequest
{
    use NormalizesDecimals;

    public function authorize(): bool
    {
        $c = $this->route('contract');

        return $c instanceof Contract ? (bool) $this->user()?->can('update', $c) : (bool) $this->user()?->can('create', Contract::class);
    }

    public function rules(): array
    {
        $c = $this->route('contract');
        $req = $c ? 'sometimes' : 'required';
        $company = Rule::exists('companies', 'id')->whereNull('deleted_at');

        return [
            'lock_version' => [$c ? 'required' : 'prohibited', 'integer'],
            'contract_type' => [$c ? 'prohibited' : 'required', Rule::in(Contract::TYPES)],
            'title' => [$req, 'string', 'max:200'],
            'customer_company_id' => [$req, 'integer', $company],
            'vessel_id' => ['nullable', 'integer', Rule::exists('vessels', 'id')->whereNull('deleted_at')],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'extension_options' => ['nullable', 'string', 'max:5000'],
            'currency' => [$req, ...Rules::currency(true)],
            'payment_terms_days' => ['nullable', 'integer', 'between:0,365'],
            'payment_terms_text' => ['nullable', 'string', 'max:500'],
            ...ContractRules::commissions(),
            'terms' => ['nullable', 'string', 'max:50000'],
            'remarks' => ['nullable', 'string', 'max:5000'],
            ...($c ? [] : [...ContractRules::rates('rates', false), ...ContractRules::clauses('clauses', false)]),
        ];
    }
}
