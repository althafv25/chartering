<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Operations\OffshoreProjectResource;
use App\Models\Contract;
use App\Models\OffshoreProject;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OffshoreProjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('operations.offshore-projects.view');
        $f = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(OffshoreProject::STATUSES)],
            'client_company_id' => ['nullable', 'integer'], 'contract_id' => ['nullable', 'integer']]);
        $q = OffshoreProject::query()->with(['client', 'contract', 'location'])->withCount('activities');
        foreach (['status', 'client_company_id', 'contract_id'] as $col) {
            if (! empty($f[$col])) {
                $q->where($col, $f[$col]);
            }
        }
        if ($term = trim((string) ($f['search'] ?? ''))) {
            $like = ListQuery::like($term);
            $q->where(fn ($w) => $w->where('code', 'like', $like)->orWhere('name', 'like', $like)->orWhere('field_name', 'like', $like));
        }

        return $this->ok(OffshoreProjectResource::collection($q->orderByDesc('id')->paginate($this->perPage($request))));
    }

    public function show(Request $request, OffshoreProject $offshoreProject): JsonResponse
    {
        $this->authorize('operations.offshore-projects.view');
        $res = new OffshoreProjectResource($offshoreProject->load(['client', 'contract', 'location'])->loadCount('activities'));
        $hours = $offshoreProject->activities()->toBase()
            ->selectRaw('COALESCE(SUM(billable_hours),0) b, COALESCE(SUM(non_billable_hours),0) nb, COALESCE(SUM(standby_hours),0) s')->first();
        $res->summary = ['billable_hours' => (string) ($hours->b ?? '0'), 'non_billable_hours' => (string) ($hours->nb ?? '0'), 'standby_hours' => (string) ($hours->s ?? '0'),
            'revenue' => $request->user()?->can('contracts.rates.view') ? $offshoreProject->activities()->toBase()->whereIn('status', ['verified', 'invoiced'])
                ->groupBy('currency')->selectRaw('currency, SUM(revenue_amount) amount')->get()->map(fn ($r) => ['currency' => $r->currency, 'amount' => (string) $r->amount])->all() : null];

        return $this->ok($res);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('operations.offshore-projects.manage');
        $d = $this->rules($request, null);
        $p = DB::transaction(fn () => OffshoreProject::query()->create([...$this->normalize($d), 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]));

        return $this->created(new OffshoreProjectResource($p->load(['client', 'contract', 'location'])), "Project {$p->code} created.");
    }

    public function update(Request $request, OffshoreProject $offshoreProject): JsonResponse
    {
        $this->authorize('operations.offshore-projects.manage');
        $d = $this->rules($request, $offshoreProject);
        $p = DB::transaction(function () use ($offshoreProject, $d, $request) {
            $p = OffshoreProject::query()->lockForUpdate()->findOrFail($offshoreProject->id);
            $p->assertLockVersion((int) $d['lock_version']);
            if (array_key_exists('contract_id', $d) && $d['contract_id'] !== $p->contract_id && $p->activities()->exists()) {
                throw new BusinessRuleException('The contract cannot change once activities are recorded.', 'project_in_use');
            }
            $p->fill([...$this->normalize($d), 'updated_by' => $request->user()->id])->save();

            return $p;
        });

        return $this->ok(new OffshoreProjectResource($p->load(['client', 'contract', 'location'])), 'Project updated.');
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    private function normalize(array $d): array
    {
        unset($d['lock_version']);
        if (! empty($d['contract_id'])) {
            $contract = Contract::query()->findOrFail($d['contract_id']);
            if (isset($d['client_company_id']) && (int) $d['client_company_id'] !== $contract->customer_company_id) {
                throw new BusinessRuleException('The client must be the contract customer.', 'validation_failed', ['client_company_id' => ['Must match the contract customer.']], 422);
            }
            $d['client_company_id'] = $contract->customer_company_id;
        }
        if (! empty($d['start_date']) && ! empty($d['end_date']) && $d['end_date'] < $d['start_date']) {
            throw new BusinessRuleException('End date must be on or after the start date.', 'validation_failed', ['end_date' => ['Before start.']], 422);
        }

        return $d;
    }

    /** @return array<string, mixed> */
    private function rules(Request $request, ?OffshoreProject $p): array
    {
        $req = $p ? 'sometimes' : 'required';

        return $request->validate([
            'lock_version' => [$p ? 'required' : 'nullable', 'integer'],
            'code' => [$req, 'string', 'max:30', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/', Rule::unique('offshore_projects', 'code')->ignore($p?->id)],
            'name' => [$req, 'string', 'max:150'],
            'client_company_id' => [$p ? 'sometimes' : 'required_without:contract_id', 'nullable', 'integer', Rule::exists('companies', 'id')],
            'contract_id' => ['nullable', 'integer', Rule::exists('contracts', 'id')->whereNotIn('status', ['draft', 'cancelled'])],
            'offshore_location_id' => ['nullable', 'integer', Rule::exists('offshore_locations', 'id')],
            'field_name' => ['nullable', 'string', 'max:120'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'status' => ['sometimes', Rule::in(OffshoreProject::STATUSES)],
            'remarks' => ['nullable', 'string', 'max:5000'],
        ]);
    }
}
