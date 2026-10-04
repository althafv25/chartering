<?php

namespace App\Http\Requests\Chartering;

use App\Models\Estimation;
use App\Support\Rules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEstimationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', Estimation::class);
    }

    public function rules(): array
    {
        return [
            'enquiry_id' => ['nullable', 'integer', Rule::exists('enquiries', 'id')->whereNull('deleted_at')],
            'vessel_id' => ['required', 'integer', Rule::exists('vessels', 'id')->whereNull('deleted_at')],
            'estimation_type' => ['nullable', Rule::in(Estimation::TYPES)],
            'title' => ['nullable', 'string', 'max:200'],
            'currency' => Rules::currency(),
            'consumption_profile_id' => ['nullable', 'integer'],
            'remarks' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
