<?php

namespace App\Http\Controllers\Api\V1\Masters;

use App\Domain\Geo\RoutePoint;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\OffshoreLocation;
use App\Models\Port;
use App\Models\PortDistance;
use App\Services\DistanceService;
use App\Support\ListQuery;
use App\Support\Rules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DistanceController extends Controller
{
    public function __construct(private readonly DistanceService $distances) {}

    /** Combined port + offshore-location picker. */
    public function points(Request $request): JsonResponse
    {
        $this->authorize(Permission::DistancesView->value);
        $term = (string) ($request->validate(['search' => ['nullable', 'string', 'max:100']])['search'] ?? '');
        $like = ListQuery::like($term);

        $ports = Port::query()->where('status', 'active')
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('unlocode', 'like', $like)))
            ->orderBy('name')->limit(15)->get()
            ->map(fn (Port $p) => ['type' => 'port', 'id' => $p->id, 'label' => $p->label()]);
        $locations = OffshoreLocation::query()->where('status', 'active')
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('code', 'like', $like)))
            ->orderBy('name')->limit(10)->get()
            ->map(fn (OffshoreLocation $l) => ['type' => 'location', 'id' => $l->id, 'label' => "{$l->name} (offshore)"]);

        return $this->ok($ports->concat($locations)->values());
    }

    public function calculate(Request $request): JsonResponse
    {
        $this->authorize(Permission::DistancesView->value);
        $d = $this->validatePoints($request);

        $from = $this->distances->point($d['from_type'], (int) $d['from_id']);
        $to = $this->distances->point($d['to_type'], (int) $d['to_id']);
        $result = $this->distances->calculate($from, $to, $d['route_key'] ?? '');

        return $this->ok([...$result->toArray(), 'from' => $from->label, 'to' => $to->label]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize(Permission::DistancesView->value);
        $f = $request->validate([
            'point_type' => ['nullable', Rule::in(RoutePoint::TYPES), 'required_with:point_id'],
            'point_id' => ['nullable', 'integer', 'required_with:point_type'],
            'provider' => ['nullable', 'string', 'max:50'],
        ]);

        $page = $this->distances->paginate($f, $this->perPage($request));
        $labels = $this->distances->labels($page->items());
        $page->through(fn (PortDistance $r) => [
            'id' => $r->id,
            'from' => ['type' => $r->from_type, 'id' => $r->from_id, 'label' => $labels["{$r->from_type}:{$r->from_id}"] ?? '?'],
            'to' => ['type' => $r->to_type, 'id' => $r->to_id, 'label' => $labels["{$r->to_type}:{$r->to_id}"] ?? '?'],
            'route_key' => $r->route_key,
            'distance_nm' => $r->distance_nm,
            'eca_distance_nm' => $r->eca_distance_nm,
            'provider' => $r->provider,
            'notes' => $r->notes,
            'calculated_at' => $r->calculated_at->toIso8601String(),
            'created_by' => $r->creator?->name,
        ]);

        return $this->page($page);
    }

    /** Manual entry / override (always provider = manual; wins over provider rows). */
    public function store(Request $request): JsonResponse
    {
        $this->authorize(Permission::DistancesOverride->value);
        $d = $this->validatePoints($request, [
            'distance_nm' => Rules::decimal(8, 2, required: true),
            'eca_distance_nm' => Rules::decimal(8, 2),
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        if (isset($d['eca_distance_nm']) && bccomp((string) $d['eca_distance_nm'], (string) $d['distance_nm'], 2) > 0) {
            throw ValidationException::withMessages(['eca_distance_nm' => ['ECA distance cannot exceed the total distance.']]);
        }

        $from = $this->distances->point($d['from_type'], (int) $d['from_id']);
        $to = $this->distances->point($d['to_type'], (int) $d['to_id']);
        $row = $this->distances->store($from, $to, $d['route_key'] ?? '', (string) $d['distance_nm'], (string) ($d['eca_distance_nm'] ?? '0'), 'manual', $d['notes'] ?? null, $request->user());

        return $this->created(['id' => $row->id, 'distance_nm' => $row->distance_nm, 'eca_distance_nm' => $row->eca_distance_nm, 'provider' => $row->provider], 'Distance saved.');
    }

    public function destroy(PortDistance $distance): JsonResponse
    {
        $this->authorize(Permission::DistancesOverride->value);
        $distance->delete();

        return $this->deleted('Distance deleted.');
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function validatePoints(Request $request, array $extra = []): array
    {
        return $request->validate([
            'from_type' => ['required', Rule::in(RoutePoint::TYPES)],
            'from_id' => ['required', 'integer'],
            'to_type' => ['required', Rule::in(RoutePoint::TYPES)],
            'to_id' => ['required', 'integer'],
            'route_key' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9:_\-]*$/'],
            ...$extra,
        ]);
    }
}
