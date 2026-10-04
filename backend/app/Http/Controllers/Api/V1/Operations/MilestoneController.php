<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Http\Resources\Operations\MilestoneResource;
use App\Models\Voyage;
use App\Models\VoyageMilestone;
use App\Services\Operations\MilestoneService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MilestoneController extends Controller
{
    public function __construct(private readonly MilestoneService $milestones) {}

    public function store(Request $request, Voyage $voyage): JsonResponse
    {
        $this->authorize('operations.milestones.manage');
        $m = $this->milestones->save($voyage, null, $this->rules($request, true), $request->user());

        return $this->created($this->res($m), 'Milestone recorded.');
    }

    public function update(Request $request, Voyage $voyage, VoyageMilestone $milestone): JsonResponse
    {
        $this->authorize('operations.milestones.manage');

        return $this->ok($this->res($this->milestones->save($voyage, $milestone, $this->rules($request, false), $request->user())), 'Milestone updated.');
    }

    public function verify(Request $request, Voyage $voyage, VoyageMilestone $milestone): JsonResponse
    {
        $this->authorize('operations.milestones.manage');

        return $this->ok($this->res($this->milestones->verify($milestone, $request->user())), 'Milestone verified.');
    }

    public function destroy(Voyage $voyage, VoyageMilestone $milestone): JsonResponse
    {
        $this->authorize('operations.milestones.manage');
        $this->milestones->delete($voyage, $milestone);

        return $this->deleted('Milestone removed.');
    }

    /** @return array<string, mixed> */
    private function rules(Request $request, bool $creating): array
    {
        return $request->validate([
            'milestone_type_id' => [$creating ? 'required' : 'sometimes', 'integer', Rule::exists('milestone_types', 'id')->where('status', 'active')],
            'port_call_id' => ['nullable', 'integer'],
            'planned_at' => ['nullable', 'string', 'max:40'],
            'actual_at' => ['nullable', 'string', 'max:40'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function res(VoyageMilestone $m): MilestoneResource
    {
        return new MilestoneResource($m->load(['type', 'portCall.port', 'portCall.location', 'verifier']));
    }
}
