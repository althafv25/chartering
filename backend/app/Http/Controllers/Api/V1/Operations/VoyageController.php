<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Api\V1\Chartering\ActivityResponder;
use App\Http\Controllers\Controller;
use App\Http\Resources\Chartering\VoyageResource;
use App\Models\Voyage;
use App\Repositories\FixtureRepository;
use App\Services\Operations\VoyageLifecycleService;
use App\Services\Operations\VoyageMetricsService;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VoyageController extends Controller
{
    use ActivityResponder;

    public function __construct(private readonly VoyageLifecycleService $lifecycle, private readonly VoyageMetricsService $metrics,
        private readonly FixtureRepository $repo) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Voyage::class);
        $f = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'string', 'max:20'],
            'conversion_type' => ['nullable', 'in:fixture,direct_estimation'], 'vessel_id' => ['nullable', 'integer'],
            'operation_type' => ['nullable', 'in:voyage,time_charter,offshore'], 'open' => ['nullable', 'boolean']]);

        return $this->ok(VoyageResource::collection($this->repo->voyages($f, $this->perPage($request))));
    }

    public function show(Voyage $voyage): JsonResponse
    {
        $this->authorize('view', $voyage);

        return $this->ok($this->detail($voyage));
    }

    public function update(Request $request, Voyage $voyage): JsonResponse
    {
        $this->authorize('operations.voyages.update');
        $d = $request->validate(['lock_version' => ['required', 'integer'], 'remarks' => ['nullable', 'string', 'max:5000']]);

        return $this->ok($this->detail($this->lifecycle->update($voyage, $d, $request->user())), 'Voyage updated.');
    }

    public function transition(Request $request, Voyage $voyage): JsonResponse
    {
        $this->authorize('operations.voyages.update');
        $d = $request->validate(['status' => ['required', Rule::in(Voyage::STATUSES)], 'at' => ['nullable', 'string', 'max:40'], 'note' => ['nullable', 'string', 'max:1000']]);
        $v = $this->lifecycle->transition($voyage, $d['status'], $request->user(), $this->at($request, $d['at'] ?? null), $d['note'] ?? null);

        return $this->ok($this->detail($v), 'Status changed to '.str_replace('_', ' ', $v->status).'.');
    }

    public function complete(Request $request, Voyage $voyage): JsonResponse
    {
        $this->authorize('operations.voyages.complete');
        $d = $request->validate(['at' => ['nullable', 'string', 'max:40']]);

        return $this->ok($this->detail($this->lifecycle->complete($voyage, $request->user(), $this->at($request, $d['at'] ?? null))), 'Voyage completed.');
    }

    public function finalize(Request $request, Voyage $voyage): JsonResponse
    {
        $this->authorize('operations.voyages.finalize');
        $d = $request->validate(['waivers' => ['nullable', 'array'], 'waivers.*' => ['nullable', 'string', 'max:1000']]);

        return $this->ok($this->detail($this->lifecycle->finalize($voyage, $request->user(), $d['waivers'] ?? [])), 'Voyage finalized — final snapshot saved.');
    }

    public function financeGates(Voyage $voyage): JsonResponse
    {
        $this->authorize('view', $voyage);

        return $this->ok($this->lifecycle->financeGateStatus($voyage));
    }

    public function reopen(Request $request, Voyage $voyage): JsonResponse
    {
        $this->authorize('operations.voyages.reopen');
        $d = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);

        return $this->ok($this->detail($this->lifecycle->reopen($voyage, $request->user(), $d['reason'])), 'Voyage reopened.');
    }

    public function cancel(Request $request, Voyage $voyage): JsonResponse
    {
        $this->authorize('operations.voyages.cancel');
        $d = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);

        return $this->ok($this->detail($this->lifecycle->cancel($voyage, $request->user(), $d['reason'])), 'Voyage cancelled.');
    }

    public function storeSnapshot(Request $request, Voyage $voyage): JsonResponse
    {
        $this->authorize('operations.voyages.update');
        $d = $request->validate(['name' => ['required', 'string', 'min:2', 'max:120']]);
        $s = $this->lifecycle->createMilestoneSnapshot($voyage, $request->user(), $d['name']);

        return $this->created(['id' => $s->id, 'type' => $s->type, 'name' => $d['name'], 'payload' => $s->payload, 'created_at' => $s->created_at?->toIso8601String()],
            "Snapshot \"{$d['name']}\" saved.");
    }

    public function comparison(Voyage $voyage): JsonResponse
    {
        $this->authorize('view', $voyage);

        return $this->ok($this->metrics->comparison($voyage));
    }

    public function history(Voyage $voyage): JsonResponse
    {
        $this->authorize('view', $voyage);

        return $this->activity([
            'voyages' => [$voyage->id],
            'port-calls' => $voyage->portCalls()->pluck('id')->all(),
            'off-hire-events' => $voyage->offHires()->pluck('id')->all(),
            'voyage-milestones' => $voyage->milestones()->pluck('voyage_milestones.id')->all(),
        ]);
    }

    /** Event time: explicit offset honoured, otherwise the user's timezone. */
    private function at(Request $request, ?string $at): ?CarbonImmutable
    {
        return LocalTime::toUtc($at, (string) ($request->user()?->getAttribute('timezone') ?: 'UTC'), 'at');
    }

    private function detail(Voyage $voyage): VoyageResource
    {
        $voyage->unsetRelations();
        $resource = new VoyageResource($voyage->load(['vessel', 'estimation', 'scenario', 'fixture', 'contract', 'charterer', 'snapshots',
            'portCalls.port', 'portCalls.location', 'portCalls.agent', 'milestones.type', 'milestones.portCall.port', 'milestones.portCall.location',
            'milestones.verifier', 'offHires.decider']));
        $resource->withChildren = true;

        return $resource;
    }
}
