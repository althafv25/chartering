<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Http\Resources\Finance\VoyageRevenueResource;
use App\Models\VoyageRevenue;
use App\Services\Finance\VoyageRevenueService;
use App\Support\Rules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VoyageRevenueController extends Controller
{
    private const WITH_DETAIL = ['voyage', 'contract', 'offshoreActivity', 'laytimeCalculation', 'category', 'invoiceLine'];

    public function __construct(private readonly VoyageRevenueService $revenues) {}

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'voyage_id' => ['nullable', 'integer'],
            'contract_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['draft', 'confirmed', 'invoiced', 'cancelled'])],
            'is_estimate' => ['nullable', 'boolean'],
        ]);

        return $this->ok(VoyageRevenueResource::collection(
            $this->revenues->paginate($request->user(), $f, $this->perPage($request))
        ));
    }

    public function show(Request $request, VoyageRevenue $voyageRevenue): JsonResponse
    {
        return $this->ok(new VoyageRevenueResource($this->revenues->find($request->user(), $voyageRevenue->id)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->rules($request, true);
        $revenue = $this->revenues->create($data, $request->user());

        return $this->created($this->res($revenue), 'Revenue line created.');
    }

    public function update(Request $request, VoyageRevenue $voyageRevenue): JsonResponse
    {
        $data = $this->rules($request, false);

        return $this->ok($this->res($this->revenues->update($voyageRevenue, $data, $request->user())), 'Revenue line updated.');
    }

    public function confirm(Request $request, VoyageRevenue $voyageRevenue): JsonResponse
    {
        return $this->ok($this->res($this->revenues->confirm($voyageRevenue, $request->user())), 'Revenue line confirmed.');
    }

    public function cancel(Request $request, VoyageRevenue $voyageRevenue): JsonResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);

        return $this->ok($this->res($this->revenues->cancel($voyageRevenue, $request->user(), $d['reason'])), 'Revenue line cancelled.');
    }

    public function destroy(Request $request, VoyageRevenue $voyageRevenue): JsonResponse
    {
        $this->revenues->delete($voyageRevenue, $request->user());

        return $this->deleted('Revenue line deleted.');
    }

    /** @return array<string, mixed> */
    private function rules(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'voyage_id' => ['nullable', 'integer', Rule::exists('voyages', 'id')],
            'contract_id' => ['nullable', 'integer', Rule::exists('contracts', 'id')],
            'offshore_activity_id' => ['nullable', 'integer', Rule::exists('offshore_activities', 'id')],
            'laytime_calculation_id' => ['nullable', 'integer', Rule::exists('laytime_calculations', 'id')],
            'revenue_category_id' => [$req, 'integer', Rule::exists('revenue_categories', 'id')],
            'description' => [$req, 'string', 'max:255'],
            'is_estimate' => ['nullable', 'boolean'],
            'quantity' => Rules::decimal(12, 4),
            'rate' => Rules::decimal(12, 4),
            'amount' => Rules::decimal(16, 2),
            'currency' => Rules::currency($creating),
            'commission_pct_total' => Rules::decimal(3, 4),
            'service_period_from' => ['nullable', 'date'],
            'service_period_to' => ['nullable', 'date', 'after_or_equal:service_period_from'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function res(VoyageRevenue $revenue): VoyageRevenueResource
    {
        return new VoyageRevenueResource($revenue->load(self::WITH_DETAIL));
    }
}
