<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Http\Resources\Operations\PortDaResource;
use App\Models\PortDa;
use App\Services\Operations\PortDaService;
use App\Support\Rules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PortDaController extends Controller
{
    private const WITH = ['port', 'portCall', 'voyage', 'agent', 'proforma', 'items.category'];

    public function __construct(private readonly PortDaService $das) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('operations.port-da.view');
        $f = $request->validate([
            'voyage_id' => ['nullable', 'integer'],
            'port_call_id' => ['nullable', 'integer'],
            'da_type' => ['nullable', Rule::in(PortDa::TYPES)],
            'status' => ['nullable', Rule::in(PortDa::STATUSES)],
            'search' => ['nullable', 'string', 'max:60'],
        ]);
        $q = PortDa::query()->with(self::WITH);
        foreach (['voyage_id', 'port_call_id', 'da_type', 'status'] as $col) {
            if (! empty($f[$col])) {
                $q->where($col, $f[$col]);
            }
        }
        if ($term = trim((string) ($f['search'] ?? ''))) {
            $q->where('da_number', 'like', "%{$term}%");
        }

        return $this->ok(PortDaResource::collection($q->orderByDesc('id')->paginate($this->perPage($request))));
    }

    public function show(PortDa $portDa): JsonResponse
    {
        $this->authorize('operations.port-da.view');

        return $this->ok($this->res($portDa));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('operations.port-da.create');
        $d = $this->das->save(null, $this->rules($request, true), $request->user());

        return $this->created($this->res($d), "DA {$d->da_number} created.");
    }

    public function update(Request $request, PortDa $portDa): JsonResponse
    {
        $this->authorize('operations.port-da.update');

        return $this->ok($this->res($this->das->save($portDa, $this->rules($request, false), $request->user())), 'DA updated.');
    }

    public function saveItems(Request $request, PortDa $portDa): JsonResponse
    {
        $this->authorize('operations.port-da.update');
        $d = $request->validate([
            'items' => ['required', 'array', 'max:200'],
            'items.*.da_cost_category_id' => ['required', 'integer', Rule::exists('da_cost_categories', 'id')],
            'items.*.description' => ['nullable', 'string', 'max:255'],
            'items.*.estimated_amount' => Rules::decimal(16, 2),
            'items.*.actual_amount' => Rules::decimal(16, 2),
            'items.*.remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->ok($this->res($this->das->saveItems($portDa, $d['items'], $request->user())), 'DA items saved.');
    }

    public function submit(Request $request, PortDa $portDa): JsonResponse
    {
        $this->authorize('operations.port-da.update');

        return $this->ok($this->res($this->das->submit($portDa, $request->user())), 'DA submitted for approval.');
    }

    public function approve(Request $request, PortDa $portDa): JsonResponse
    {
        $this->authorize('operations.port-da.approve');

        return $this->ok($this->res($this->das->approve($portDa, $request->user())), 'DA approved.');
    }

    public function reject(Request $request, PortDa $portDa): JsonResponse
    {
        $this->authorize('operations.port-da.approve');

        return $this->ok($this->res($this->das->reject($portDa, $request->user())), 'DA returned to draft.');
    }

    /** @return array<string, mixed> */
    private function rules(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'lock_version' => [$creating ? 'nullable' : 'required', 'integer'],
            'port_call_id' => [$creating ? 'required' : 'prohibited', 'integer', Rule::exists('port_calls', 'id')],
            'voyage_id' => [$creating ? 'required' : 'prohibited', 'integer', Rule::exists('voyages', 'id')],
            'port_id' => [$creating ? 'required' : 'prohibited', 'integer', Rule::exists('ports', 'id')],
            'agent_company_id' => ['nullable', 'integer', Rule::exists('companies', 'id')],
            'da_type' => [$req, Rule::in(PortDa::TYPES)],
            'proforma_da_id' => ['nullable', 'integer', Rule::exists('port_das', 'id')],
            'currency' => Rules::currency($creating),
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function res(PortDa $d): PortDaResource
    {
        return new PortDaResource($d->load(self::WITH));
    }
}
