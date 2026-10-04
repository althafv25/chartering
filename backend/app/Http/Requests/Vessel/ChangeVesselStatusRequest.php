<?php

namespace App\Http\Requests\Vessel;

use App\Enums\VesselStatusTrack;
use App\Support\Rules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeVesselStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('vessel-status.update');
    }

    public function rules(): array
    {
        $track = VesselStatusTrack::tryFrom((string) $this->input('track'));

        return [
            'track' => ['required', Rule::enum(VesselStatusTrack::class)],
            'status' => ['required', Rule::in($track?->statuses() ?? [])],
            'effective_from' => ['required', 'date'],
            'port_id' => ['nullable', 'integer', Rule::exists('ports', 'id')->whereNull('deleted_at'), 'prohibits:offshore_location_id'],
            'offshore_location_id' => ['nullable', 'integer', Rule::exists('offshore_locations', 'id')->whereNull('deleted_at')],
            'location_text' => ['nullable', 'string', 'max:150'],
            'latitude' => Rules::latitude(),
            'longitude' => Rules::longitude(),
            'reason' => ['nullable', 'string', 'max:150'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
