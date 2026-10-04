<?php

namespace App\Http\Controllers\Api\V1\Chartering;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chartering\SaveScenarioRequest;
use App\Http\Resources\Chartering\ScenarioResource;
use App\Models\Estimation;
use App\Models\EstimationScenario;
use App\Services\Chartering\ScenarioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScenarioController extends Controller
{
    public function __construct(private readonly ScenarioService $scenarios) {}

    public function show(Estimation $estimation, EstimationScenario $scenario): JsonResponse
    {
        $this->authorize('view', $estimation);

        return $this->ok(new ScenarioResource($scenario->load('result')));
    }

    /** New scenario from master defaults, or a clone of an existing one (clone_from_id). */
    public function store(Request $request, Estimation $estimation): JsonResponse
    {
        $this->authorize('update', $estimation);
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'clone_from_id' => ['nullable', 'integer'],
            'consumption_profile_id' => ['nullable', 'integer'],
        ]);

        if (! empty($data['clone_from_id'])) {
            $source = $estimation->scenarios()->findOrFail($data['clone_from_id']);
            $scenario = $this->scenarios->clone($source->setRelation('estimation', $estimation), $data['name'] ?? null, $request->user());
        } else {
            $scenario = $this->scenarios->createFromDefaults($estimation, $data['name'] ?? null, $data['consumption_profile_id'] ?? null, $request->user());
        }

        return $this->created(new ScenarioResource($scenario->load('result')), "Scenario {$scenario->code} created.");
    }

    public function update(SaveScenarioRequest $request, Estimation $estimation, EstimationScenario $scenario): JsonResponse
    {
        $scenario->setRelation('estimation', $estimation);
        $scenario = $this->scenarios->update($scenario, $request->validated(), $request->user());

        return $this->ok(new ScenarioResource($scenario->load('result')), $scenario->calc_status === 'calculated' ? 'Saved and calculated.' : 'Saved — inputs incomplete.');
    }

    public function calculate(Estimation $estimation, EstimationScenario $scenario): JsonResponse
    {
        $this->authorize('view', $estimation);
        $scenario->setRelation('estimation', $estimation);

        return $this->ok(new ScenarioResource($this->scenarios->calculate($scenario)->load('result')), 'Calculated.');
    }

    public function select(Request $request, Estimation $estimation, EstimationScenario $scenario): JsonResponse
    {
        $this->authorize('update', $estimation);
        $scenario->setRelation('estimation', $estimation);

        return $this->ok(new ScenarioResource($this->scenarios->select($scenario, $request->user())->load('result')), "Scenario {$scenario->code} selected.");
    }

    public function refreshDefaults(Request $request, Estimation $estimation, EstimationScenario $scenario): JsonResponse
    {
        $this->authorize('update', $estimation);
        $data = $request->validate(['vessel' => ['sometimes', 'boolean'], 'consumption' => ['sometimes', 'boolean'], 'profile_id' => ['nullable', 'integer']]);
        $scenario->setRelation('estimation', $estimation);

        return $this->ok(new ScenarioResource($this->scenarios->refreshDefaults($scenario, $data, $request->user())->load('result')), 'Defaults refreshed.');
    }
}
