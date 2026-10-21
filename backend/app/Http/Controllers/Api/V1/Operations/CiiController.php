<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Http\Resources\Operations\CiiFormulaSetResource;
use App\Http\Resources\Operations\CiiVesselYearResource;
use App\Models\CiiFormulaSet;
use App\Models\CiiVesselYear;
use App\Services\Operations\CiiService;
use App\Support\Rules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CII (Carbon Intensity Indicator). Read access is `cii.view`; entering data,
 * calculating and verifying is `cii.manage`. Formula sets are managed the same
 * way — there is no separate "formulas.manage" permission (see Permission enum).
 */
class CiiController extends Controller
{
    private const WITH = ['vessel.vesselType', 'formulaSet', 'verifiedBy'];

    public function __construct(private readonly CiiService $cii) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('cii.view');
        $f = $request->validate(['vessel_id' => ['nullable', 'integer'], 'reporting_year' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(CiiVesselYear::STATUSES)], 'rating' => ['nullable', Rule::in(CiiVesselYear::RATINGS)]]);
        $q = CiiVesselYear::query()->with(self::WITH);
        foreach (['vessel_id', 'reporting_year', 'status', 'rating'] as $col) {
            if (! empty($f[$col])) {
                $q->where($col, $f[$col]);
            }
        }

        return $this->ok(CiiVesselYearResource::collection($q->orderByDesc('reporting_year')->orderBy('vessel_id')->paginate($this->perPage($request))));
    }

    public function show(CiiVesselYear $ciiVesselYear): JsonResponse
    {
        $this->authorize('cii.view');

        return $this->ok(new CiiVesselYearResource($ciiVesselYear->load(self::WITH)));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('cii.manage');
        $y = $this->cii->save(null, $this->rules($request, true), $request->user());

        return $this->created(new CiiVesselYearResource($y->load(self::WITH)), 'CII record created.');
    }

    public function update(Request $request, CiiVesselYear $ciiVesselYear): JsonResponse
    {
        $this->authorize('cii.manage');

        return $this->ok(new CiiVesselYearResource($this->cii->save($ciiVesselYear, $this->rules($request, false), $request->user())->load(self::WITH)), 'CII record updated.');
    }

    public function calculate(CiiVesselYear $ciiVesselYear): JsonResponse
    {
        $this->authorize('cii.manage');

        return $this->ok(new CiiVesselYearResource($this->cii->calculate($ciiVesselYear, request()->user())->load(self::WITH)), 'CII calculated.');
    }

    public function verify(Request $request, CiiVesselYear $ciiVesselYear): JsonResponse
    {
        $this->authorize('cii.manage');

        return $this->ok(new CiiVesselYearResource($this->cii->verify($ciiVesselYear, $request->user())->load(self::WITH)), 'CII record verified.');
    }

    public function reopen(Request $request, CiiVesselYear $ciiVesselYear): JsonResponse
    {
        $this->authorize('cii.manage');
        $d = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->ok(new CiiVesselYearResource($this->cii->reopen($ciiVesselYear, $request->user(), $d['reason'])->load(self::WITH)), 'CII record reopened.');
    }

    public function fleetSummary(Request $request): JsonResponse
    {
        $this->authorize('cii.view');
        $d = $request->validate(['reporting_year' => ['required', 'integer', 'min:2000', 'max:2100']]);

        return $this->ok($this->cii->fleetSummary((int) $d['reporting_year']));
    }

    public function formulaSets(): JsonResponse
    {
        $this->authorize('cii.view');

        return $this->ok(CiiFormulaSetResource::collection(CiiFormulaSet::query()->orderByDesc('effective_date')->get()));
    }

    public function storeFormulaSet(Request $request): JsonResponse
    {
        $this->authorize('cii.manage');
        $fs = $this->cii->saveFormulaSet(null, $this->formulaSetRules($request, true), $request->user());

        return $this->created(new CiiFormulaSetResource($fs), "Formula set {$fs->code} created.");
    }

    public function updateFormulaSet(Request $request, CiiFormulaSet $formulaSet): JsonResponse
    {
        $this->authorize('cii.manage');
        $fs = $this->cii->saveFormulaSet($formulaSet, $this->formulaSetRules($request, false), $request->user());

        return $this->ok(new CiiFormulaSetResource($fs), 'Formula set updated.');
    }

    public function verifyFormulaSet(Request $request, CiiFormulaSet $formulaSet): JsonResponse
    {
        $this->authorize('cii.manage');

        return $this->ok(new CiiFormulaSetResource($this->cii->markFormulaSetStatus($formulaSet, 'verified', $request->user())), 'Formula set verified.');
    }

    public function retireFormulaSet(Request $request, CiiFormulaSet $formulaSet): JsonResponse
    {
        $this->authorize('cii.manage');

        return $this->ok(new CiiFormulaSetResource($this->cii->markFormulaSetStatus($formulaSet, 'retired', $request->user())), 'Formula set retired.');
    }

    /** @return array<string, mixed> */
    private function rules(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $d = $request->validate([
            'lock_version' => [$creating ? 'nullable' : 'required', 'integer'],
            'vessel_id' => [$creating ? 'required' : 'prohibited', 'integer', Rule::exists('vessels', 'id')],
            'reporting_year' => [$creating ? 'required' : 'prohibited', 'integer', 'min:2000', 'max:2100'],
            'formula_set_id' => [$req, 'integer', Rule::exists('cii_formula_sets', 'id')],
            'capacity_metric' => [$req, Rule::in(['DWT', 'GT'])],
            'capacity_value' => Rules::decimal(9, 3, $creating),
            'distance_sailed_nm' => Rules::decimal(9, 2, $creating),
            'fuel_consumption' => [$req, 'array'],
            'fuel_consumption.*' => Rules::decimal(9, 3, false),
            'scope_status' => ['nullable', Rule::in(CiiVesselYear::SCOPE_STATUSES)],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);
        foreach (['capacity_value', 'distance_sailed_nm'] as $k) {
            if (isset($d[$k])) {
                $d[$k] = (string) $d[$k];
            }
        }
        if (isset($d['fuel_consumption'])) {
            $d['fuel_consumption'] = array_map('strval', $d['fuel_consumption']);
        }

        return $d;
    }

    /** @return array<string, mixed> */
    private function formulaSetRules(Request $request, bool $creating): array
    {
        return $request->validate([
            'code' => [$creating ? 'required' : 'prohibited', 'string', 'max:50', Rule::unique('cii_formula_sets', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'parameters' => ['required', 'array'],
            'parameters.reference_lines' => ['required', 'array'],
            'parameters.reduction_factors' => ['nullable', 'array'],
            'parameters.rating_boundaries' => ['required', 'array'],
            'parameters.emission_factors' => ['required', 'array'],
            'effective_date' => ['required', 'date'],
            'expiration_date' => ['nullable', 'date', 'after:effective_date'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
