<?php

namespace App\Http\Requests\Port;

use App\Models\OffshoreLocation;
use App\Support\Rules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveOffshoreLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $loc = $this->route('offshore_location');

        return $loc instanceof OffshoreLocation ? (bool) $this->user()?->can('update', $loc) : (bool) $this->user()?->can('create', OffshoreLocation::class);
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
        }
    }

    public function rules(): array
    {
        $loc = $this->route('offshore_location');
        $req = $loc ? 'sometimes' : 'required';

        return [
            'code' => [$req, 'string', 'max:30', 'regex:/^[A-Z0-9\-_]+$/', Rule::unique('offshore_locations', 'code')->ignore($loc)],
            'name' => [$req, 'string', 'max:120'],
            'field_name' => ['nullable', 'string', 'max:120'],
            'block' => ['nullable', 'string', 'max:60'],
            'operator_company_id' => ['nullable', 'integer', Rule::exists('companies', 'id')->whereNull('deleted_at')],
            'nearest_port_id' => ['nullable', 'integer', Rule::exists('ports', 'id')->whereNull('deleted_at')],
            'latitude' => [...Rules::latitude(! $loc), ...($loc ? ['sometimes'] : [])],
            'longitude' => [...Rules::longitude(! $loc), ...($loc ? ['sometimes'] : [])],
            'water_depth_m' => Rules::decimal(6, 2),
            'timezone' => ['sometimes', 'timezone:all'],
            'remarks' => ['nullable', 'string', 'max:5000'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }
}
