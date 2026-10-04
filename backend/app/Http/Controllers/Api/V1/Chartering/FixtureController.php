<?php

namespace App\Http\Controllers\Api\V1\Chartering;

use App\Http\Controllers\Controller;
use App\Http\Resources\Chartering\FixtureResource;
use App\Http\Resources\Chartering\VoyageResource;
use App\Http\Resources\Contracts\ContractResource;
use App\Models\Contract;
use App\Models\Fixture;
use App\Models\Voyage;
use App\Repositories\FixtureRepository;
use App\Services\Chartering\FixtureWorkflowService;
use App\Services\Contracts\FixtureConversionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Read side for fixtures and voyages created by phase 4 conversions. */
class FixtureController extends Controller
{
    use ActivityResponder;

    public function __construct(private readonly FixtureRepository $repo) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Fixture::class);
        $f = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'string', 'max:20']]);

        return $this->ok(FixtureResource::collection($this->repo->fixtures($f, $this->perPage($request))));
    }

    public function show(Fixture $fixture): JsonResponse
    {
        $this->authorize('view', $fixture);

        return $this->ok($this->detail($fixture));
    }

    public function update(Request $request, Fixture $fixture, FixtureWorkflowService $workflow): JsonResponse
    {
        $this->authorize('update', $fixture);
        $company = Rule::exists('companies', 'id')->whereNull('deleted_at');
        $d = $request->validate([
            'lock_version' => ['required', 'integer'], 'cargo_description' => ['nullable', 'string', 'max:255'], 'terms' => ['nullable', 'string', 'max:50000'],
            'remarks' => ['nullable', 'string', 'max:5000'], 'charterer_company_id' => ['nullable', 'integer', $company],
            'owner_company_id' => ['nullable', 'integer', $company], 'broker_company_id' => ['nullable', 'integer', $company],
        ]);

        return $this->ok($this->detail($workflow->update($fixture, $d, $request->user())), 'Fixture updated.');
    }

    public function transition(Request $request, Fixture $fixture, string $action, FixtureWorkflowService $workflow): JsonResponse
    {
        $ability = ['submit' => 'submit', 'approve' => 'approve', 'reject' => 'approve', 'fail' => 'cancel', 'cancel' => 'cancel'][$action];
        $this->authorize($ability, $fixture);
        $needsReason = in_array($action, ['reject', 'fail', 'cancel'], true);
        $d = $request->validate([$needsReason ? 'reason' : 'comment' => [$needsReason ? 'required' : 'nullable', 'string', 'min:3', 'max:1000']]);
        $u = $request->user();
        $f = match ($action) {
            'submit' => $workflow->submit($fixture, $u),
            'approve' => $workflow->approve($fixture, $u, $d['comment'] ?? null),
            'reject' => $workflow->reject($fixture, $u, $d['reason']),
            'fail' => $workflow->fail($fixture, $u, $d['reason']),
            'cancel' => $workflow->cancel($fixture, $u, $d['reason']),
            default => abort(404),
        };

        return $this->ok($this->detail($f), 'Fixture '.['submit' => 'submitted', 'approve' => 'approved', 'reject' => 'returned to draft', 'fail' => 'marked failed', 'cancel' => 'cancelled'][$action].'.');
    }

    public function toContract(Request $request, Fixture $fixture, FixtureConversionService $conversion): JsonResponse
    {
        $this->authorize('view', $fixture);
        $this->authorize('create', Contract::class);
        [$contract, $created] = $conversion->toContract($fixture, $request->user());
        $res = new ContractResource($contract->load(['customer', 'vessel', 'fixture']));

        return $created ? $this->created($res, "Contract {$contract->contract_number} created.") : $this->ok($res, 'A contract already exists for this fixture.');
    }

    public function toVoyage(Request $request, Fixture $fixture, FixtureConversionService $conversion): JsonResponse
    {
        $this->authorize('view', $fixture);
        $this->authorize('create', Voyage::class);
        [$voyage, $created] = $conversion->toVoyage($fixture, $request->user());
        $res = new VoyageResource($voyage->load(['vessel', 'estimation', 'scenario', 'fixture', 'contract', 'snapshots']));

        return $created ? $this->created($res, "Voyage {$voyage->voyage_number} created.") : $this->ok($res, 'A voyage already exists for this fixture.');
    }

    public function fixtureActivity(Fixture $fixture): JsonResponse
    {
        $this->authorize('view', $fixture);

        return $this->activity(['fixtures' => [$fixture->id]]);
    }

    private function detail(Fixture $fixture): FixtureResource
    {
        $resource = new FixtureResource($fixture->load(['vessel', 'charterer', 'enquiry', 'submitter', 'decider', 'contract', 'voyage']));
        $resource->withSnapshot = true;

        return $resource;
    }
}
