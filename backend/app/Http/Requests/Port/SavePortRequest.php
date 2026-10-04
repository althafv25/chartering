<?php

namespace App\Http\Requests\Port;

use App\Models\Port;
use App\Support\Rules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePortRequest extends FormRequest
{
    public function authorize(): bool
    {
        $port = $this->route('port');

        return $port instanceof Port ? (bool) $this->user()?->can('update', $port) : (bool) $this->user()?->can('create', Port::class);
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        if ($this->filled('unlocode')) {
            $merge['unlocode'] = strtoupper(str_replace(' ', '', (string) $this->input('unlocode')));
        }
        if ($this->filled('country')) {
            $merge['country'] = strtoupper((string) $this->input('country'));
        }
        $this->merge($merge);
    }

    public function rules(): array
    {
        $port = $this->route('port');
        $req = $port ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'max:120'],
            'unlocode' => ['nullable', 'regex:/^[A-Z]{2}[A-Z2-9]{3}$/', Rule::unique('ports', 'unlocode')->ignore($port)],
            'country' => [...Rules::country(true), ...($port ? ['sometimes'] : [])],
            'region' => ['nullable', 'string', 'max:60'],
            'latitude' => [...Rules::latitude(), 'required_with:longitude'],
            'longitude' => [...Rules::longitude(), 'required_with:latitude'],
            'timezone' => [$req, 'timezone:all'],
            'max_draft_m' => Rules::decimal(4, 2),
            'max_loa_m' => Rules::decimal(5, 2),
            'max_beam_m' => Rules::decimal(4, 2),
            'restrictions' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }

    public function messages(): array
    {
        return ['unlocode.regex' => 'UN/LOCODE must be 5 characters: 2-letter country + 3-character location (e.g. AEJEA).'];
    }

    public function after(): array
    {
        return [function ($v) {
            $code = $this->input('unlocode');
            $country = $this->input('country') ?? $this->route('port')?->country;
            if ($code && $country && ! str_starts_with($code, $country)) {
                $v->errors()->add('unlocode', 'UN/LOCODE must start with the port country code.');
            }
        }];
    }
}
