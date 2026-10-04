<?php

namespace App\Http\Requests\Contracts;

use App\Http\Requests\Chartering\NormalizesDecimals;
use Illuminate\Foundation\Http\FormRequest;

class SaveContractRatesRequest extends FormRequest
{
    use NormalizesDecimals;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', $this->route('contract')) && $this->user()->can('contracts.rates.view');
    }

    public function rules(): array
    {
        return ['lock_version' => ['required', 'integer'], ...ContractRules::rates()];
    }
}
