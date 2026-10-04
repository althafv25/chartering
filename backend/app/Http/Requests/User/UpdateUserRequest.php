<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', $this->route('user'));
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
        }
    }

    public function rules(): array
    {
        $user = $this->route('user');

        return [
            'first_name' => ['sometimes', 'required', 'string', 'max:75'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:75'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user)],
            'password' => ['sometimes', 'nullable', 'string', 'confirmed', Password::defaults()],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:100'],
            'timezone' => ['sometimes', 'required', 'string', 'timezone:all'],
            'roles' => ['sometimes', 'array', 'min:1'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
        ];
    }
}
