<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Http\Resources\Operations\PortCallResource;
use App\Models\PortCall;
use App\Models\Voyage;
use App\Services\Operations\PortCallService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PortCallController extends Controller
{
    public function __construct(private readonly PortCallService $calls) {}

    public function store(Request $request, Voyage $voyage): JsonResponse
    {
        $this->authorize('operations.port-calls.manage');
        $call = $this->calls->create($voyage, $this->rules($request, true), $request->user());

        return $this->created($this->res($call), 'Port call added.');
    }

    public function update(Request $request, Voyage $voyage, PortCall $portCall): JsonResponse
    {
        $this->authorize('operations.port-calls.manage');
        $call = $this->calls->update($portCall, $this->rules($request, false), $request->user());

        return $this->ok($this->res($call), 'Port call updated.');
    }

    public function cancel(Request $request, Voyage $voyage, PortCall $portCall): JsonResponse
    {
        $this->authorize('operations.port-calls.manage');
        $d = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->ok($this->res($this->calls->cancel($portCall, $request->user(), $d['reason'])), 'Port call cancelled.');
    }

    public function destroy(Voyage $voyage, PortCall $portCall): JsonResponse
    {
        $this->authorize('operations.port-calls.manage');
        $this->calls->delete($portCall);

        return $this->deleted('Port call removed.');
    }

    /** @return array<string, mixed> */
    private function rules(Request $request, bool $creating): array
    {
        $time = ['nullable', 'string', 'max:40'];

        return $request->validate([
            'lock_version' => [$creating ? 'nullable' : 'required', 'integer'],
            'sequence' => ['nullable', 'integer', 'min:1', 'max:999'],
            'port_id' => ['nullable', 'integer', Rule::exists('ports', 'id')],
            'offshore_location_id' => ['nullable', 'integer', Rule::exists('offshore_locations', 'id')],
            'agent_company_id' => ['nullable', 'integer', Rule::exists('companies', 'id')],
            'purpose' => [$creating ? 'required' : 'sometimes', Rule::in(PortCall::PURPOSES)],
            'berth' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['planned', 'nominated'])],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'eta' => $time, 'etb' => $time, 'etd' => $time, 'ata' => $time, 'atb' => $time, 'atd' => $time,
        ]);
    }

    private function res(PortCall $call): PortCallResource
    {
        return new PortCallResource($call->load(['port', 'location', 'agent']));
    }
}
