<?php

namespace App\Http\Controllers\Api\V1\Chartering;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chartering\StoreEstimationRequest;
use App\Http\Resources\Chartering\EstimationResource;
use App\Http\Resources\Chartering\ScenarioResource;
use App\Http\Resources\Chartering\VoyageResource;
use App\Models\Estimation;
use App\Models\EstimationScenario;
use App\Models\Voyage;
use App\Repositories\EstimationRepository;
use App\Services\Chartering\EstimationService;
use App\Services\Chartering\VoyageConversionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EstimationController extends Controller
{
    use ActivityResponder;

    public function __construct(private readonly EstimationService $estimations, private readonly EstimationRepository $repo) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Estimation::class);
        $f = $request->validate([
            'search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['draft', 'submitted', 'approved', 'rejected'])],
            'estimation_type' => ['nullable', Rule::in(Estimation::TYPES)], 'vessel_id' => ['nullable', 'integer'], 'enquiry_id' => ['nullable', 'integer'],
            'sort' => ['nullable', 'string', 'max:30'],
        ]);

        return $this->ok(EstimationResource::collection($this->repo->paginate($f, $this->perPage($request))));
    }

    public function show(Estimation $estimation): JsonResponse
    {
        $this->authorize('view', $estimation);

        return $this->ok(new EstimationResource($this->repo->detail($estimation)));
    }

    public function store(StoreEstimationRequest $request): JsonResponse
    {
        $est = $this->estimations->create($request->validated(), $request->user());

        return $this->created(new EstimationResource($this->repo->detail($est)), 'Estimation created with scenario A.');
    }

    public function update(Request $request, Estimation $estimation): JsonResponse
    {
        $this->authorize('update', $estimation);
        $data = $request->validate(['lock_version' => ['required', 'integer'], 'title' => ['sometimes', 'string', 'max:200'], 'remarks' => ['nullable', 'string', 'max:5000']]);

        return $this->ok(new EstimationResource($this->repo->detail($this->estimations->update($estimation, $data, $request->user()))), 'Estimation updated.');
    }

    public function submit(Request $request, Estimation $estimation): JsonResponse
    {
        $this->authorize('submit', $estimation);

        return $this->ok(new EstimationResource($this->repo->detail($this->estimations->submit($estimation, $request->user()))), 'Submitted for approval.');
    }

    public function approve(Request $request, Estimation $estimation): JsonResponse
    {
        $this->authorize('approve', $estimation);
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:1000']]);

        return $this->ok(new EstimationResource($this->repo->detail($this->estimations->approve($estimation, $request->user(), $data['comment'] ?? null))), 'Estimation approved.');
    }

    public function reject(Request $request, Estimation $estimation): JsonResponse
    {
        $this->authorize('reject', $estimation);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);

        return $this->ok(new EstimationResource($this->repo->detail($this->estimations->reject($estimation, $request->user(), $data['reason']))), 'Estimation rejected.');
    }

    public function reopen(Request $request, Estimation $estimation): JsonResponse
    {
        $this->authorize('update', $estimation);

        return $this->ok(new EstimationResource($this->repo->detail($this->estimations->reopen($estimation, $request->user()))), 'Estimation reopened as draft.');
    }

    public function clone(Request $request, Estimation $estimation): JsonResponse
    {
        $this->authorize('clone', $estimation);

        return $this->created(new EstimationResource($this->repo->detail($this->estimations->clone($estimation, $request->user()))), 'Estimation cloned.');
    }

    /** Side-by-side key figures of all scenarios. */
    public function compare(Estimation $estimation): JsonResponse
    {
        $this->authorize('view', $estimation);
        $rows = $estimation->scenarios()->with('result')->get()->map(fn (EstimationScenario $s) => [
            'id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'is_selected' => $s->is_selected, 'calc_status' => $s->calc_status,
            'speed_kn' => collect($s->inputs['legs'] ?? [])->pluck('speed_kn')->filter()->unique()->values(),
            'fuel_prices' => collect($s->inputs['fuel_prices'] ?? [])->map(fn ($p) => ['code' => $p['fuel_code'] ?? null, 'price' => $p['price_per_mt'] ?? null])->values(),
            'result' => $s->result ? ScenarioResource::result($s->result, true) : null,
        ]);

        return $this->ok(['currency' => $estimation->currency, 'scenarios' => $rows]);
    }

    public function convertToVoyage(Request $request, Estimation $estimation, VoyageConversionService $conversion): JsonResponse
    {
        $this->authorize('create', Voyage::class);
        $this->authorize('view', $estimation);
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);

        [$voyage, $created] = $conversion->directFromEstimation($estimation, $data['reason'], $request->user());
        $resource = new VoyageResource($voyage->load(['vessel', 'estimation', 'scenario', 'snapshots']));

        return $created ? $this->created($resource, "Voyage {$voyage->voyage_number} created.") : $this->ok($resource, 'Voyage already exists for this estimation.');
    }

    public function history(Estimation $estimation): JsonResponse
    {
        $this->authorize('view', $estimation);

        return $this->activity(['estimations' => [$estimation->id], 'estimation-scenarios' => $estimation->scenarios()->pluck('id')->all()]);
    }
}
