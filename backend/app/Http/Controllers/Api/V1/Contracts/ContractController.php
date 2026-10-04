<?php

namespace App\Http\Controllers\Api\V1\Contracts;

use App\Http\Controllers\Api\V1\Chartering\ActivityResponder;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contracts\ContractRules;
use App\Http\Requests\Contracts\SaveContractRatesRequest;
use App\Http\Requests\Contracts\SaveContractRequest;
use App\Http\Resources\Contracts\ContractResource;
use App\Models\Contract;
use App\Repositories\ContractRepository;
use App\Services\Contracts\ContractRateResolver;
use App\Services\Contracts\ContractService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContractController extends Controller
{
    use ActivityResponder;

    public function __construct(private readonly ContractService $contracts, private readonly ContractRepository $repo) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Contract::class);
        $f = $request->validate([
            'search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(Contract::STATUSES)],
            'contract_type' => ['nullable', Rule::in(Contract::TYPES)], 'customer_company_id' => ['nullable', 'integer'], 'vessel_id' => ['nullable', 'integer'],
            'expiring_within_days' => ['nullable', 'integer', 'between:1,365'], 'sort' => ['nullable', 'string', 'max:30'],
        ]);

        return $this->ok(ContractResource::collection($this->repo->paginate($f, $this->perPage($request))));
    }

    public function show(Contract $contract): JsonResponse
    {
        $this->authorize('view', $contract);

        return $this->ok(new ContractResource($this->repo->detail($contract)));
    }

    public function store(SaveContractRequest $request): JsonResponse
    {
        $d = $request->validated();
        $c = $this->contracts->create($d, $d['rates'] ?? [], $d['clauses'] ?? [], $request->user());

        return $this->created(new ContractResource($this->repo->detail($c)), "Contract {$c->contract_number} created.");
    }

    public function update(SaveContractRequest $request, Contract $contract): JsonResponse
    {
        return $this->ok(new ContractResource($this->repo->detail($this->contracts->update($contract, $request->validated(), $request->user()))), 'Contract updated.');
    }

    public function rates(SaveContractRatesRequest $request, Contract $contract): JsonResponse
    {
        $d = $request->validated();

        return $this->ok(new ContractResource($this->repo->detail($this->contracts->replaceRates($contract, $d['rates'], (int) $d['lock_version'], $request->user()))), 'Rates saved.');
    }

    public function clauses(Request $request, Contract $contract): JsonResponse
    {
        $this->authorize('update', $contract);
        $d = $request->validate(['lock_version' => ['required', 'integer'], ...ContractRules::clauses()]);

        return $this->ok(new ContractResource($this->repo->detail($this->contracts->replaceClauses($contract, $d['clauses'], (int) $d['lock_version'], $request->user()))), 'Clauses saved.');
    }

    public function submit(Request $request, Contract $contract): JsonResponse
    {
        $this->authorize('submit', $contract);

        return $this->ok(new ContractResource($this->repo->detail($this->contracts->submit($contract, $request->user()))), 'Submitted for review.');
    }

    public function approve(Request $request, Contract $contract): JsonResponse
    {
        $this->authorize('approve', $contract);
        $d = $request->validate(['comment' => ['nullable', 'string', 'max:1000']]);

        return $this->ok(new ContractResource($this->repo->detail($this->contracts->approve($contract, $request->user(), $d['comment'] ?? null))), 'Contract approved (version 1).');
    }

    public function reject(Request $request, Contract $contract): JsonResponse
    {
        $this->authorize('approve', $contract);
        $d = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);

        return $this->ok(new ContractResource($this->repo->detail($this->contracts->reject($contract, $request->user(), $d['reason']))), 'Contract returned to draft.');
    }

    public function activate(Request $request, Contract $contract): JsonResponse
    {
        $this->authorize('activate', $contract);

        return $this->ok(new ContractResource($this->repo->detail($this->contracts->activate($contract, $request->user()))), 'Contract activated.');
    }

    public function complete(Request $request, Contract $contract): JsonResponse
    {
        $this->authorize('activate', $contract);
        $d = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return $this->ok(new ContractResource($this->repo->detail($this->contracts->complete($contract, $request->user(), $d['note'] ?? null))), 'Contract completed.');
    }

    public function cancel(Request $request, Contract $contract): JsonResponse
    {
        $this->authorize('cancel', $contract);
        $d = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->ok(new ContractResource($this->repo->detail($this->contracts->cancel($contract, $request->user(), $d['reason']))), 'Contract cancelled.');
    }

    public function effectiveRates(Request $request, Contract $contract, ContractRateResolver $resolver): JsonResponse
    {
        $this->authorize('view', $contract);
        $this->authorize('contracts.rates.view');
        $d = $request->validate(['date' => ['required', 'date']]);
        $version = $resolver->versionAt($contract, $d['date']);

        return $this->ok([
            'date' => $d['date'],
            'version_no' => $version?->version_no,
            'rates' => $resolver->ratesAt($contract, $d['date'])->map(fn ($r) => ['id' => $r->id, 'version_no' => $r->version_no, ...$r->canonical()])->values(),
        ]);
    }

    public function history(Contract $contract): JsonResponse
    {
        $this->authorize('view', $contract);

        return $this->activity(['contracts' => [$contract->id], 'contract-amendments' => $contract->amendments()->pluck('id')->all()]);
    }
}
