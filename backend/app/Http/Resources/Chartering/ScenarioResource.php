<?php

namespace App\Http\Resources\Chartering;

use App\Models\EstimationScenario;
use App\Models\ScenarioResultRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EstimationScenario
 */
class ScenarioResource extends JsonResource
{
    /** Summary only (lists / comparison): no inputs, no trace. */
    public bool $summary = false;

    public function toArray(Request $request): array
    {
        $s = $this->resource;
        $result = $s->relationLoaded('result') ? $s->result : null;

        return [
            'id' => $s->id,
            'estimation_id' => $s->estimation_id,
            'code' => $s->code,
            'name' => $s->name,
            'is_selected' => $s->is_selected,
            'calc_status' => $s->calc_status,
            'calc_issues' => $s->calc_issues ?? [],
            'calculation_version' => $s->calculation_version,
            'inputs_hash' => $s->inputs_hash,
            'calculated_at' => $s->calculated_at?->toIso8601String(),
            'defaults_refreshed_at' => $s->defaults_refreshed_at?->toIso8601String(),
            'cloned_from_id' => $s->cloned_from_id,
            'consumption_profile_id' => $s->getAttribute('consumption_profile_id'),
            'notes' => $s->getAttribute('notes'),
            'lock_version' => $s->lock_version,
            'vessel_snapshot' => $this->when(! $this->summary, $s->vessel_snapshot),
            'inputs' => $this->when(! $this->summary, $s->inputs),
            'result' => $result ? self::result($result, $this->summary) : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function result(ScenarioResultRecord $r, bool $summary = false): array
    {
        $data = collect($r->getAttributes())->except(['id', 'scenario_id', 'created_at', 'updated_at', 'breakdown', 'trace', 'warnings'])
            ->map(fn ($v, $k) => $r->getAttribute($k))->all();
        $data['calculated_at'] = $r->calculated_at->toIso8601String();
        $data['warnings'] = $r->warnings ?? [];
        if (! $summary) {
            $data['breakdown'] = $r->breakdown;
            $data['trace'] = $r->trace;
        }

        return $data;
    }
}
