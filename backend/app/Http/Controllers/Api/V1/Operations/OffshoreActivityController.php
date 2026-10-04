<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Http\Resources\Operations\OffshoreActivityResource;
use App\Models\OffshoreActivity;
use App\Services\Operations\OffshoreActivityService;
use App\Support\ListQuery;
use App\Support\Rules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OffshoreActivityController extends Controller
{
    private const WITH = ['vessel', 'voyage', 'contract', 'project', 'client', 'location', 'type', 'submitter', 'verifier'];

    public function __construct(private readonly OffshoreActivityService $activities) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('operations.offshore-activities.view');
        $q = $this->filtered($request);
        $page = (clone $q)->with(self::WITH)->orderByDesc('start_at')->orderByDesc('id')->paginate($this->perPage($request));

        $response = $this->ok(OffshoreActivityResource::collection($page));
        $response->setData([...$response->getData(true), 'summary' => $this->summary($q, $request)]);

        return $response;
    }

    public function show(OffshoreActivity $offshoreActivity): JsonResponse
    {
        $this->authorize('operations.offshore-activities.view');

        return $this->ok($this->res($offshoreActivity));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('operations.offshore-activities.create');
        $a = $this->activities->save(null, $this->rules($request, true), $request->user());

        return $this->created($this->res($a), "Activity {$a->activity_number} saved as draft.");
    }

    public function update(Request $request, OffshoreActivity $offshoreActivity): JsonResponse
    {
        $this->authorize('operations.offshore-activities.update');

        return $this->ok($this->res($this->activities->save($offshoreActivity, $this->rules($request, false), $request->user())), 'Activity updated.');
    }

    public function submit(Request $request, OffshoreActivity $offshoreActivity): JsonResponse
    {
        $this->authorize('operations.offshore-activities.update');

        return $this->ok($this->res($this->activities->submit($offshoreActivity, $request->user())), 'Activity submitted for verification.');
    }

    public function verify(Request $request, OffshoreActivity $offshoreActivity): JsonResponse
    {
        $this->authorize('operations.offshore-activities.verify');
        $d = $request->validate(['comment' => ['nullable', 'string', 'max:1000']]);

        return $this->ok($this->res($this->activities->verify($offshoreActivity, $request->user(), $d['comment'] ?? null)), 'Activity verified.');
    }

    public function reject(Request $request, OffshoreActivity $offshoreActivity): JsonResponse
    {
        $this->authorize('operations.offshore-activities.verify');
        $d = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);

        return $this->ok($this->res($this->activities->reject($offshoreActivity, $request->user(), $d['reason'])), 'Activity returned to draft.');
    }

    public function destroy(OffshoreActivity $offshoreActivity): JsonResponse
    {
        $this->authorize('operations.offshore-activities.update');
        $this->activities->delete($offshoreActivity);

        return $this->deleted('Activity deleted.');
    }

    /** @return Builder<OffshoreActivity> */
    private function filtered(Request $request): Builder
    {
        $f = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'vessel_id' => ['nullable', 'integer'], 'voyage_id' => ['nullable', 'integer'],
            'contract_id' => ['nullable', 'integer'], 'offshore_project_id' => ['nullable', 'integer'], 'offshore_activity_type_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(OffshoreActivity::STATUSES)], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $q = OffshoreActivity::query();
        foreach (['vessel_id', 'voyage_id', 'contract_id', 'offshore_project_id', 'offshore_activity_type_id', 'status'] as $col) {
            if (! empty($f[$col])) {
                $q->where($col, $f[$col]);
            }
        }
        if (! empty($f['from'])) {
            $q->where('end_at', '>=', $f['from']);
        }
        if (! empty($f['to'])) {
            $q->where('start_at', '<', date('Y-m-d', (int) strtotime($f['to'].' +1 day')));
        }
        if ($term = trim((string) ($f['search'] ?? ''))) {
            $like = ListQuery::like($term);
            $q->where(fn ($w) => $w->where('activity_number', 'like', $like)->orWhere('description', 'like', $like));
        }

        return $q;
    }

    /**
     * Totals for the filtered set. MySQL DECIMAL sums are exact; returned as strings.
     *
     * @param  Builder<OffshoreActivity>  $q
     * @return array<string, mixed>
     */
    private function summary(Builder $q, Request $request): array
    {
        $hours = (clone $q)->toBase()->selectRaw('COUNT(*) n, COALESCE(SUM(billable_hours),0) b, COALESCE(SUM(non_billable_hours),0) nb, COALESCE(SUM(standby_hours),0) s')->first();
        $out = ['count' => (int) ($hours->n ?? 0), 'billable_hours' => (string) ($hours->b ?? '0'), 'non_billable_hours' => (string) ($hours->nb ?? '0'),
            'standby_hours' => (string) ($hours->s ?? '0'), 'revenue' => null];
        if ($request->user()?->can('contracts.rates.view')) {
            $out['revenue'] = (clone $q)->toBase()->whereIn('status', ['verified', 'invoiced'])->whereNotNull('revenue_amount')->groupBy('currency')
                ->selectRaw('currency, SUM(revenue_amount) amount')->get()->map(fn ($r) => ['currency' => $r->currency, 'amount' => (string) $r->amount])->values()->all();
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function rules(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'lock_version' => [$creating ? 'nullable' : 'required', 'integer'],
            'vessel_id' => [$req, 'integer', Rule::exists('vessels', 'id')],
            'voyage_id' => ['nullable', 'integer', Rule::exists('voyages', 'id')],
            'contract_id' => ['nullable', 'integer', Rule::exists('contracts', 'id')],
            'offshore_project_id' => ['nullable', 'integer', Rule::exists('offshore_projects', 'id')->whereNull('deleted_at')],
            'offshore_location_id' => ['nullable', 'integer', Rule::exists('offshore_locations', 'id')],
            'offshore_activity_type_id' => [$req, 'integer', Rule::exists('offshore_activity_types', 'id')->where('status', 'active')],
            'start_at' => [$req, 'string', 'max:40'],
            'end_at' => [$req, 'string', 'max:40'],
            'billable_hours' => Rules::decimal(6, 4),
            'non_billable_hours' => Rules::decimal(6, 4),
            'standby_hours' => Rules::decimal(6, 4),
            'description' => ['nullable', 'string', 'max:2000'],
            'remarks' => ['nullable', 'string', 'max:5000'],
            'fuel_used' => ['nullable', 'array', 'max:10'],
            'fuel_used.*.fuel_type_id' => ['required', 'integer', Rule::exists('fuel_types', 'id')],
            'fuel_used.*.mt' => Rules::decimal(9, 3, true),
        ]);
    }

    private function res(OffshoreActivity $a): OffshoreActivityResource
    {
        return new OffshoreActivityResource($a->load(self::WITH));
    }
}
