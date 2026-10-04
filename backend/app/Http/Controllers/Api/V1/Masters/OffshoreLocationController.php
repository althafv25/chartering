<?php

namespace App\Http\Controllers\Api\V1\Masters;

use App\Http\Controllers\Controller;
use App\Http\Requests\Port\SaveOffshoreLocationRequest;
use App\Http\Resources\Masters\OffshoreLocationResource;
use App\Models\OffshoreLocation;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OffshoreLocationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', OffshoreLocation::class);
        $f = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:active,inactive']]);

        $items = OffshoreLocation::query()->with(['operator', 'nearestPort'])
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', ListQuery::like($s))
                ->orWhere('code', 'like', ListQuery::like($s))->orWhere('field_name', 'like', ListQuery::like($s))))
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderBy('name')->paginate($this->perPage($request));

        return $this->ok(OffshoreLocationResource::collection($items));
    }

    public function show(OffshoreLocation $offshoreLocation): JsonResponse
    {
        $this->authorize('view', $offshoreLocation);

        return $this->ok(new OffshoreLocationResource($offshoreLocation->load(['operator', 'nearestPort'])));
    }

    public function store(SaveOffshoreLocationRequest $request): JsonResponse
    {
        $loc = OffshoreLocation::query()->create([...$request->validated(), 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);

        return $this->created(new OffshoreLocationResource($loc->load(['operator', 'nearestPort'])), 'Offshore location created.');
    }

    public function update(SaveOffshoreLocationRequest $request, OffshoreLocation $offshoreLocation): JsonResponse
    {
        $offshoreLocation->fill([...$request->validated(), 'updated_by' => $request->user()->id])->save();

        return $this->ok(new OffshoreLocationResource($offshoreLocation->load(['operator', 'nearestPort'])), 'Offshore location updated.');
    }

    public function destroy(OffshoreLocation $offshoreLocation): JsonResponse
    {
        $this->authorize('delete', $offshoreLocation);
        $offshoreLocation->delete();

        return $this->deleted('Offshore location deleted.');
    }
}
