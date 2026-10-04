<?php

namespace App\Http\Requests\Role;

use App\Enums\Permission;
use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $role = $this->route('role');

        return $role instanceof Role
            ? (bool) $this->user()?->can('update', $role)
            : (bool) $this->user()?->can('create', Role::class);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', (string) $this->input('name')), '-'))]);
        }
    }

    public function rules(): array
    {
        $role = $this->route('role');

        return [
            'name' => [$role ? 'sometimes' : 'required', 'string', 'min:2', 'max:60', Rule::unique('roles', 'name')->ignore($role)],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(Permission::values())],
        ];
    }
}
