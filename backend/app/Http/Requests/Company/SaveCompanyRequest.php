<?php

namespace App\Http\Requests\Company;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Support\Rules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = $this->route('company');

        return $company instanceof Company
            ? (bool) $this->user()?->can('update', $company)
            : (bool) $this->user()?->can('create', Company::class);
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('email')) {
            $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
        }
        if ($this->filled('country')) {
            $this->merge(['country' => strtoupper((string) $this->input('country'))]);
        }
    }

    public function rules(): array
    {
        $company = $this->route('company');
        $req = $company ? 'sometimes' : 'required';

        return [
            'lock_version' => [$company ? 'required' : 'prohibited', 'integer'],
            'confirm_duplicate' => ['sometimes', 'boolean'],
            'legal_name' => [$req, 'string', 'max:200'],
            'trading_name' => ['nullable', 'string', 'max:200'],
            'roles' => [$req, 'array', 'min:1'],
            'roles.*' => ['string', Rule::in(CompanyRole::values())],
            'country' => Rules::country(),
            'city' => ['nullable', 'string', 'max:100'],
            'address_line1' => ['nullable', 'string', 'max:200'],
            'address_line2' => ['nullable', 'string', 'max:200'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:50'],
            'website' => ['nullable', 'url:http,https', 'max:200'],
            'tax_number' => ['nullable', 'string', 'max:50'],
            'vat_registered' => ['sometimes', 'boolean'],
            'default_currency' => Rules::currency(),
            'payment_terms_days' => ['nullable', 'integer', 'between:0,365'],
            'credit_limit' => Rules::decimal(16, 2),
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'blocked'])],
            'remarks' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
