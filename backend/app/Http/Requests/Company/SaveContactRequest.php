<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;

class SaveContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', $this->route('company'));
    }

    public function rules(): array
    {
        $req = $this->route('contact') ? 'sometimes' : 'required';

        return [
            'first_name' => [$req, 'string', 'max:75'],
            'last_name' => ['nullable', 'string', 'max:75'],
            'job_title' => ['nullable', 'string', 'max:100'],
            'department' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:50'],
            'mobile' => ['nullable', 'string', 'max:50'],
            'is_primary' => ['sometimes', 'boolean'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
