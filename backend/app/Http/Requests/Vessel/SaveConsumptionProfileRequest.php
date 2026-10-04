<?php

namespace App\Http\Requests\Vessel;

use App\Enums\ConsumptionMode;
use App\Support\Rules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveConsumptionProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', $this->route('vessel'));
    }

    public function rules(): array
    {
        $req = $this->route('profile') ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'max:80'],
            'source' => ['sometimes', Rule::in(['design', 'charter_party', 'observed'])],
            'effective_from' => [$req, 'date'],
            'effective_to' => ['nullable', 'date'],
            'is_default' => ['sometimes', 'boolean'],
            'remarks' => ['nullable', 'string', 'max:255'],
            'rates' => [$req, 'array', 'min:1', 'max:200'],
            'rates.*.mode' => ['required', Rule::in(ConsumptionMode::values())],
            'rates.*.speed_kn' => Rules::decimal(3, 2),
            'rates.*.fuel_type_id' => ['required', 'integer', Rule::exists('fuel_types', 'id')],
            'rates.*.consumption_mt_per_day' => Rules::decimal(7, 3, required: true),
        ];
    }
}
