<?php

namespace App\Http\Controllers\Api\V1\Masters;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vessel\SaveVesselRequest;
use App\Http\Resources\Masters\VesselResource;
use App\Models\Vessel;
use App\Services\VesselService;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VesselController extends Controller
{
    public function __construct(private readonly VesselService $vessels) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Vessel::class);
        $f = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'vessel_type_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'sold', 'scrapped'])],
            'commercial_status' => ['nullable', 'string', 'max:30'],
            'operational_status' => ['nullable', 'string', 'max:30'],
            'owner_company_id' => ['nullable', 'integer'],
            'ownership_type' => ['nullable', 'string', 'max:20'],
            'sort' => ['nullable', 'string', 'max:30'],
        ]);

        return $this->ok(VesselResource::collection($this->vessels->paginate($f, $this->perPage($request))));
    }

    public function lookup(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Vessel::class);
        $term = (string) ($request->validate(['search' => ['nullable', 'string', 'max:100']])['search'] ?? '');
        $like = ListQuery::like($term);

        $items = Vessel::query()->where('status', 'active')
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('code', 'like', $like)->orWhere('imo_number', 'like', $like)))
            ->orderBy('name')->limit(20)->get(['id', 'code', 'name', 'imo_number']);

        return $this->ok($items);
    }

    public function show(Vessel $vessel): JsonResponse
    {
        $this->authorize('view', $vessel);

        return $this->ok(new VesselResource($vessel->load([...VesselService::RELATIONS, 'nameHistory'])));
    }

    public function store(SaveVesselRequest $request): JsonResponse
    {
        return $this->created(new VesselResource($this->vessels->create($request->validated(), $request->user())), 'Vessel created.');
    }

    public function update(SaveVesselRequest $request, Vessel $vessel): JsonResponse
    {
        $vessel = $this->vessels->update($vessel, $request->validated(), $request->user());

        return $this->ok(new VesselResource($vessel->load('nameHistory')), 'Vessel updated.');
    }

    public function destroy(Vessel $vessel): JsonResponse
    {
        $this->authorize('delete', $vessel);
        $this->vessels->delete($vessel);

        return $this->deleted('Vessel deleted.');
    }
}
