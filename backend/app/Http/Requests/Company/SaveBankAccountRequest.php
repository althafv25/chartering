<?php

namespace App\Http\Requests\Company;

use App\Support\Rules;
use Illuminate\Foundation\Http\FormRequest;

class SaveBankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', $this->route('company')) && $this->user()->can('companies.bank-view');
    }

    protected function prepareForValidation(): void
    {
        foreach (['iban', 'swift_bic'] as $k) {
            if ($this->filled($k)) {
                $this->merge([$k => strtoupper(str_replace(' ', '', (string) $this->input($k)))]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'bank_name' => ['required', 'string', 'max:150'],
            'account_name' => ['nullable', 'string', 'max:150'],
            'account_number' => ['nullable', 'string', 'max:60'],
            'iban' => ['nullable', 'string', 'regex:/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/'],
            'swift_bic' => ['nullable', 'string', 'regex:/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/'],
            'currency' => Rules::currency(),
            'is_primary' => ['sometimes', 'boolean'],
        ];
    }
}
