<?php

namespace App\Http\Controllers\Api\V1\Masters;

use App\Http\Controllers\Controller;
use App\Http\Requests\Port\SavePortRequest;
use App\Http\Resources\Masters\PortResource;
use App\Models\Port;
use App\Services\PortService;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortController extends Controller
{
    public function __construct(private readonly PortService $ports) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Port::class);
        $f = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'size:2'],
            'region' => ['nullable', 'string', 'max:60'],
            'status' => ['nullable', 'in:active,inactive'],
            'sort' => ['nullable', 'string', 'max:30'],
        ]);

        return $this->ok(PortResource::collection($this->ports->paginate($f, $this->perPage($request))));
    }

    public function lookup(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Port::class);
        $term = (string) ($request->validate(['search' => ['nullable', 'string', 'max:100']])['search'] ?? '');
        $like = ListQuery::like($term);

        $ports = Port::query()->where('status', 'active')
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('unlocode', 'like', $like)))
            ->orderBy('name')->limit(20)->get();

        return $this->ok($ports->map(fn (Port $p) => ['id' => $p->id, 'label' => $p->label(), 'country' => $p->country, 'timezone' => $p->timezone]));
    }

    public function show(Port $port): JsonResponse
    {
        $this->authorize('view', $port);

        return $this->ok(new PortResource($port->load('agents.roles')));
    }

    public function store(SavePortRequest $request): JsonResponse
    {
        return $this->created(new PortResource($this->ports->create($request->validated(), $request->user())), 'Port created.');
    }

    public function update(SavePortRequest $request, Port $port): JsonResponse
    {
        return $this->ok(new PortResource($this->ports->update($port, $request->validated(), $request->user())), 'Port updated.');
    }

    public function destroy(Port $port): JsonResponse
    {
        $this->authorize('delete', $port);
        $port->delete();

        return $this->deleted('Port deleted.');
    }

    public function addAgent(Request $request, Port $port): JsonResponse
    {
        $this->authorize('update', $port);
        $data = $request->validate([
            'company_id' => ['required', 'integer'],
            'is_default' => ['sometimes', 'boolean'],
            'remarks' => ['nullable', 'string', 'max:255'],
        ]);
        $this->ports->addAgent($port, (int) $data['company_id'], (bool) ($data['is_default'] ?? false), $data['remarks'] ?? null);

        return $this->ok(new PortResource($port->load('agents.roles')), 'Agent linked.');
    }

    public function removeAgent(Port $port, int $companyId): JsonResponse
    {
        $this->authorize('update', $port);
        $this->ports->removeAgent($port, $companyId);

        return $this->deleted('Agent unlinked.');
    }
}
