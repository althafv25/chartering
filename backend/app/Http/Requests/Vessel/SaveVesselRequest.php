<?php

namespace App\Http\Requests\Vessel;

use App\Models\Vessel;
use App\Rules\ImoNumber;
use App\Support\Rules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveVesselRequest extends FormRequest
{
    public function authorize(): bool
    {
        $vessel = $this->route('vessel');

        return $vessel instanceof Vessel
            ? (bool) $this->user()?->can('update', $vessel)
            : (bool) $this->user()?->can('create', Vessel::class);
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        foreach (['code', 'call_sign'] as $k) {
            if ($this->filled($k)) {
                $merge[$k] = strtoupper(trim((string) $this->input($k)));
            }
        }
        foreach (['imo_number', 'mmsi'] as $k) {
            if ($this->has($k)) {
                $v = preg_replace('/\D/', '', (string) $this->input($k));
                $merge[$k] = $v === '' ? null : $v;
            }
        }
        $this->merge($merge);
    }

    public function rules(): array
    {
        $vessel = $this->route('vessel');
        $req = $vessel ? 'sometimes' : 'required';
        $company = ['nullable', 'integer', Rule::exists('companies', 'id')->whereNull('deleted_at')];

        $rules = [
            'lock_version' => [$vessel ? 'required' : 'prohibited', 'integer'],
            'code' => [$req, 'string', 'max:10', 'regex:/^[A-Z0-9]+$/', Rule::unique('vessels', 'code')->ignore($vessel)],
            'name' => [$req, 'string', 'max:150'],
            'imo_number' => ['nullable', new ImoNumber, Rule::unique('vessels', 'imo_number')->ignore($vessel)],
            'mmsi' => ['nullable', 'digits:9', Rule::unique('vessels', 'mmsi')->ignore($vessel)],
            'call_sign' => ['nullable', 'string', 'max:20'],
            'official_number' => ['nullable', 'string', 'max:30'],
            'vessel_type_id' => [$req, 'integer', Rule::exists('vessel_types', 'id')->where('status', 'active')],
            'subtype' => ['nullable', 'string', 'max:60'],
            'flag_country' => Rules::country(),
            'port_of_registry' => ['nullable', 'string', 'max:100'],
            'year_built' => ['nullable', 'integer', 'between:1900,'.(now()->year + 3)],
            'builder' => ['nullable', 'string', 'max:150'],
            'class_society' => ['nullable', 'string', 'max:100'],
            'class_notation' => ['nullable', 'string', 'max:255'],
            'ownership_type' => ['sometimes', Rule::in(['owned', 'managed', 'chartered_in', 'third_party'])],
            'owner_company_id' => $company,
            'manager_company_id' => $company,
            'commercial_manager_company_id' => $company,
            'technical_manager_company_id' => $company,
            'main_engine' => ['nullable', 'string', 'max:255'],
            'aux_engines' => ['nullable', 'string', 'max:255'],
            'propulsion' => ['nullable', 'string', 'max:100'],
            'dp_class' => ['nullable', Rule::in(['DP0', 'DP1', 'DP2', 'DP3'])],
            'crew_capacity' => ['nullable', 'integer', 'between:0,1000'],
            'passenger_capacity' => ['nullable', 'integer', 'between:0,5000'],
            'custom_attributes' => ['nullable', 'array'],
            'crew_management_vessel_id' => ['nullable', 'string', 'max:40'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'sold', 'scrapped'])],
            'remarks' => ['nullable', 'string', 'max:5000'],
        ];

        // Integer digits per column = precision − scale from the migration.
        $precision = ['dwt_mt' => 12, 'gt' => 12, 'nt' => 12, 'main_engine_power_kw' => 10, 'aux_engine_power_kw' => 10,
            'service_speed_kn' => 5, 'max_speed_kn' => 5, 'eco_speed_kn' => 5, 'deck_area_m2' => 10, 'deck_strength_t_m2' => 6,
            'bollard_pull_t' => 8, 'crane_swl_t' => 8];
        foreach (Vessel::DECIMALS as $col => $scale) {
            $rules[$col] = Rules::decimal(($precision[$col] ?? 8) - $scale, $scale);
        }

        return $rules;
    }

    public function after(): array
    {
        return [function ($v) {
            $d = $this->all();
            foreach ([['eco_speed_kn', 'max_speed_kn'], ['service_speed_kn', 'max_speed_kn']] as [$low, $high]) {
                if (isset($d[$low], $d[$high]) && is_numeric($d[$low]) && is_numeric($d[$high]) && bccomp((string) $d[$low], (string) $d[$high], 2) > 0) {
                    $v->errors()->add($low, 'Must not exceed the maximum speed.');
                }
            }
            if (isset($d['lbp_m'], $d['loa_m']) && is_numeric($d['lbp_m']) && is_numeric($d['loa_m']) && bccomp((string) $d['lbp_m'], (string) $d['loa_m'], 3) > 0) {
                $v->errors()->add('lbp_m', 'LBP cannot be greater than LOA.');
            }
        }];
    }
}
