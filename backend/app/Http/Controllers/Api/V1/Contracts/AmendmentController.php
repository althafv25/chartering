<?php

namespace App\Http\Controllers\Api\V1\Contracts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contracts\SaveAmendmentRequest;
use App\Http\Resources\Contracts\AmendmentResource;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Services\Contracts\ContractAmendmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AmendmentController extends Controller
{
    public function __construct(private readonly ContractAmendmentService $amendments) {}

    public function store(SaveAmendmentRequest $request, Contract $contract): JsonResponse
    {
        if (array_key_exists('rates', $request->validated())) {
            $this->authorize('contracts.rates.view');
        }
        $a = $this->amendments->create($contract, $request->validated(), $request->user());

        return $this->created(new AmendmentResource($a), "Draft amendment {$a->amendment_no} created.");
    }

    public function update(SaveAmendmentRequest $request, Contract $contract, ContractAmendment $amendment): JsonResponse
    {
        if (array_key_exists('rates', $request->validated())) {
            $this->authorize('contracts.rates.view');
        }

        return $this->ok(new AmendmentResource($this->amendments->update($amendment, $request->validated(), $request->user())), 'Amendment updated.');
    }

    public function submit(Request $request, Contract $contract, ContractAmendment $amendment): JsonResponse
    {
        $this->authorize('amend', $contract);

        return $this->ok(new AmendmentResource($this->amendments->submit($amendment, $request->user())), 'Amendment submitted for approval.');
    }

    public function approve(Request $request, Contract $contract, ContractAmendment $amendment): JsonResponse
    {
        $this->authorize('approve', $contract);
        $d = $request->validate(['comment' => ['nullable', 'string', 'max:1000']]);
        $a = $this->amendments->approve($amendment, $request->user(), $d['comment'] ?? null);

        return $this->ok(new AmendmentResource($a), "Amendment approved — contract is now version {$a->resulting_version}.");
    }

    public function reject(Request $request, Contract $contract, ContractAmendment $amendment): JsonResponse
    {
        $this->authorize('approve', $contract);
        $d = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);

        return $this->ok(new AmendmentResource($this->amendments->reject($amendment, $request->user(), $d['reason'])), 'Amendment rejected.');
    }

    public function withdraw(Request $request, Contract $contract, ContractAmendment $amendment): JsonResponse
    {
        $this->authorize('amend', $contract);

        return $this->ok(new AmendmentResource($this->amendments->withdraw($amendment, $request->user())), 'Amendment withdrawn.');
    }
}
