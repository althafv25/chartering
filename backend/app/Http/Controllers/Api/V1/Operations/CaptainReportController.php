<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Http\Resources\Operations\CaptainReportResource;
use App\Models\CaptainReport;
use App\Services\Operations\CaptainReportService;
use App\Support\Rules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CaptainReportController extends Controller
{
    public function __construct(private readonly CaptainReportService $reports) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('operations.captain-reports.view');
        $f = $request->validate(['vessel_id' => ['nullable', 'integer'], 'voyage_id' => ['nullable', 'integer'], 'status' => ['nullable', Rule::in(CaptainReport::STATUSES)],
            'report_type' => ['nullable', Rule::in(CaptainReport::TYPES)], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $q = CaptainReport::query()->with(['vessel', 'voyage', 'portCall.port', 'portCall.location', 'fuelLines.fuelType', 'submitter', 'verifier']);
        foreach (['vessel_id', 'voyage_id', 'status', 'report_type'] as $col) {
            if (! empty($f[$col])) {
                $q->where($col, $f[$col]);
            }
        }
        if (! empty($f['from'])) {
            $q->where('reported_at', '>=', $f['from']);
        }
        if (! empty($f['to'])) {
            $q->where('reported_at', '<', date('Y-m-d', (int) strtotime($f['to'].' +1 day')));
        }

        return $this->ok(CaptainReportResource::collection($q->orderByDesc('reported_at')->orderByDesc('id')->paginate($this->perPage($request))));
    }

    public function show(CaptainReport $captainReport): JsonResponse
    {
        $this->authorize('operations.captain-reports.view');

        return $this->ok($this->res($captainReport));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('operations.captain-reports.create');
        $r = $this->reports->save(null, $this->rules($request, true), $request->user());

        return $this->created($this->res($r), 'Report saved as draft.');
    }

    public function update(Request $request, CaptainReport $captainReport): JsonResponse
    {
        $this->authorize('operations.captain-reports.update');

        return $this->ok($this->res($this->reports->save($captainReport, $this->rules($request, false), $request->user())), 'Report updated.');
    }

    public function submit(Request $request, CaptainReport $captainReport): JsonResponse
    {
        $this->authorize('operations.captain-reports.update');

        return $this->ok($this->res($this->reports->submit($captainReport, $request->user())), 'Report submitted for verification.');
    }

    public function verify(Request $request, CaptainReport $captainReport): JsonResponse
    {
        $this->authorize('operations.captain-reports.verify');
        $d = $request->validate(['comment' => ['nullable', 'string', 'max:1000'], 'apply_to_port_call' => ['nullable', 'boolean']]);
        $r = $this->reports->verify($captainReport, $request->user(), $d['comment'] ?? null, (bool) ($d['apply_to_port_call'] ?? false));

        return $this->ok($this->res($r), 'Report verified.');
    }

    public function reject(Request $request, CaptainReport $captainReport): JsonResponse
    {
        $this->authorize('operations.captain-reports.verify');
        $d = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);

        return $this->ok($this->res($this->reports->reject($captainReport, $request->user(), $d['reason'])), 'Report rejected.');
    }

    public function destroy(CaptainReport $captainReport): JsonResponse
    {
        $this->authorize('operations.captain-reports.update');
        $this->reports->delete($captainReport);

        return $this->deleted('Report deleted.');
    }

    /** @return array<string, mixed> */
    private function rules(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'lock_version' => [$creating ? 'nullable' : 'required', 'integer'],
            'vessel_id' => [$creating ? 'required' : 'prohibited', 'integer', Rule::exists('vessels', 'id')],
            'voyage_id' => [$creating ? 'nullable' : 'prohibited', 'integer', Rule::exists('voyages', 'id')],
            'port_call_id' => ['nullable', 'integer'],
            'report_type' => [$req, Rule::in(CaptainReport::TYPES)],
            // ISO 8601 with offset (ship's time + zone) — stored in UTC.
            'reported_at' => [$req, 'date'],
            'latitude' => Rules::latitude(),
            'longitude' => ['nullable', 'numeric', 'decimal:0,6', 'between:-180,180'],
            'speed_kn' => Rules::decimal(3, 2),
            'course_deg' => ['nullable', 'integer', 'between:0,360'],
            'distance_since_last_nm' => Rules::decimal(7, 2),
            'distance_to_go_nm' => Rules::decimal(7, 2),
            'wind_force_bft' => ['nullable', 'integer', 'between:0,12'],
            'wind_direction' => ['nullable', 'string', 'max:10'],
            'sea_state' => ['nullable', 'string', 'max:30'],
            'weather_text' => ['nullable', 'string', 'max:255'],
            'main_engine_hours' => Rules::decimal(5, 2),
            'aux_engine_hours' => Rules::decimal(5, 2),
            'activity_text' => ['nullable', 'string', 'max:5000'],
            'delay_hours' => Rules::decimal(5, 2),
            'delay_reason' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:5000'],
            'fuel_lines' => ['sometimes', 'array', 'max:10'],
            'fuel_lines.*.fuel_type_id' => ['required', 'integer', Rule::exists('fuel_types', 'id')],
            'fuel_lines.*.rob_mt' => Rules::decimal(9, 3),
            'fuel_lines.*.consumed_mt' => Rules::decimal(9, 3),
            'fuel_lines.*.received_mt' => Rules::decimal(9, 3),
        ]);
    }

    private function res(CaptainReport $r): CaptainReportResource
    {
        return new CaptainReportResource($r->load(['vessel', 'voyage', 'portCall.port', 'portCall.location', 'fuelLines.fuelType', 'submitter', 'verifier']));
    }
}
