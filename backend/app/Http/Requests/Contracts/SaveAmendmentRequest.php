<?php

namespace App\Http\Requests\Contracts;

use App\Http\Requests\Chartering\NormalizesDecimals;
use Illuminate\Foundation\Http\FormRequest;

class SaveAmendmentRequest extends FormRequest
{
    use NormalizesDecimals;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('amend', $this->route('contract'));
    }

    public function rules(): array
    {
        $req = $this->route('amendment') ? 'sometimes' : 'required';

        return [
            'lock_version' => [$this->route('amendment') ? 'required' : 'prohibited', 'integer'],
            'effective_date' => [$req, 'date'],
            'summary' => [$req, 'string', 'min:5', 'max:500'],
            'header' => ['sometimes', 'nullable', 'array'],
            'header.title' => ['sometimes', 'string', 'max:200'],
            'header.end_date' => ['sometimes', 'date'],
            'header.extension_options' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'header.payment_terms_days' => ['sometimes', 'nullable', 'integer', 'between:0,365'],
            'header.payment_terms_text' => ['sometimes', 'nullable', 'string', 'max:500'],
            'header.terms' => ['sometimes', 'nullable', 'string', 'max:50000'],
            ...ContractRules::commissions('header.commissions'),
            ...ContractRules::rates('rates', false),
            ...ContractRules::clauses('clauses', false),
            'rates' => ['sometimes', 'nullable', 'array', 'max:100'],
            'clauses' => ['sometimes', 'nullable', 'array', 'max:200'],
            'header.currency' => ['prohibited'],
            'header.customer_company_id' => ['prohibited'],
            'header.vessel_id' => ['prohibited'],
            'header.start_date' => ['prohibited'],
        ];
    }
}
