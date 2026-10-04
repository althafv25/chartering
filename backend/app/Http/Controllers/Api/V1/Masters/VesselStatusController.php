<?php

namespace App\Http\Controllers\Api\V1\Masters;

use App\Enums\Permission;
use App\Enums\VesselStatusTrack;
use App\Http\Controllers\Controller;
use App\Http\Requests\Vessel\ChangeVesselStatusRequest;
use App\Http\Resources\Masters\VesselStatusResource;
use App\Models\Vessel;
use App\Models\VesselStatusHistory;
use App\Services\VesselStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VesselStatusController extends Controller
{
    public function __construct(private readonly VesselStatusService $status) {}

    public function catalogue(): JsonResponse
    {
        return $this->ok(collect(VesselStatusTrack::cases())->mapWithKeys(fn (VesselStatusTrack $t) => [$t->value => $t->statuses()]));
    }

    public function board(Request $request): JsonResponse
    {
        $this->authorize(Permission::VesselStatusView->value);
        $f = $request->validate([
            'vessel_type_id' => ['nullable', 'integer'],
            'commercial_status' => ['nullable', 'string', 'max:30'],
            'operational_status' => ['nullable', 'string', 'max:30'],
        ]);

        $rows = $this->status->board($f)->map(function (Vessel $v) use ($request) {
            $current = $v->statusHistory->keyBy('track');

            return [
                'vessel' => ['id' => $v->id, 'code' => $v->code, 'name' => $v->name, 'type' => $v->vesselType->name],
                'commercial' => isset($current['commercial']) ? (new VesselStatusResource($current['commercial']))->resolve($request) : null,
                'operational' => isset($current['operational']) ? (new VesselStatusResource($current['operational']))->resolve($request) : null,
            ];
        });

        return $this->ok($rows);
    }

    public function history(Request $request, Vessel $vessel): JsonResponse
    {
        $this->authorize(Permission::VesselStatusView->value);
        $f = $request->validate(['track' => ['nullable', Rule::enum(VesselStatusTrack::class)]]);

        $items = VesselStatusHistory::query()->with(['port', 'offshoreLocation', 'changer'])
            ->where('vessel_id', $vessel->id)
            ->when($f['track'] ?? null, fn ($q, $t) => $q->where('track', $t))
            ->orderByDesc('effective_from')->orderByDesc('id')
            ->paginate($this->perPage($request, 25));

        return $this->ok(VesselStatusResource::collection($items));
    }

    public function store(ChangeVesselStatusRequest $request, Vessel $vessel): JsonResponse
    {
        $data = $request->validated();
        $entry = $this->status->change($vessel, VesselStatusTrack::from($data['track']), $data, $request->user());

        return $this->created(new VesselStatusResource($entry), 'Status updated.');
    }

    public function undo(Request $request, Vessel $vessel): JsonResponse
    {
        $this->authorize(Permission::VesselStatusUpdate->value);
        $track = VesselStatusTrack::from($request->validate(['track' => ['required', Rule::enum(VesselStatusTrack::class)]])['track']);

        $previous = $this->status->undoLatest($vessel, $track, $request->user());

        return $this->ok($previous ? new VesselStatusResource($previous->load(['port', 'offshoreLocation', 'changer'])) : null, 'Latest status change undone.');
    }
}
