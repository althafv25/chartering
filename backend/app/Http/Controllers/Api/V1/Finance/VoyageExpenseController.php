<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Http\Resources\Finance\VoyageExpenseResource;
use App\Models\VoyageExpense;
use App\Services\Finance\VoyageExpenseService;
use App\Support\Rules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VoyageExpenseController extends Controller
{
    private const WITH = ['voyage', 'contract', 'category', 'supplier', 'source'];

    public function __construct(private readonly VoyageExpenseService $expenses) {}

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'voyage_id' => ['nullable', 'integer'],
            'contract_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['draft', 'confirmed', 'approved', 'paid', 'cancelled'])],
            'supplier_company_id' => ['nullable', 'integer'],
        ]);

        return $this->ok(VoyageExpenseResource::collection(
            $this->expenses->paginate($request->user(), $f, $this->perPage($request))
        ));
    }

    public function show(Request $request, VoyageExpense $voyageExpense): JsonResponse
    {
        return $this->ok(new VoyageExpenseResource($this->expenses->find($request->user(), $voyageExpense->id)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->rules($request, true);
        $expense = $this->expenses->create($data, $request->user());

        return $this->created($this->res($expense), 'Expense line created.');
    }

    public function update(Request $request, VoyageExpense $voyageExpense): JsonResponse
    {
        $data = $this->rules($request, false);

        return $this->ok($this->res($this->expenses->update($voyageExpense, $data, $request->user())), 'Expense line updated.');
    }

    public function confirm(Request $request, VoyageExpense $voyageExpense): JsonResponse
    {
        return $this->ok($this->res($this->expenses->confirm($voyageExpense, $request->user())), 'Expense line confirmed.');
    }

    public function approve(Request $request, VoyageExpense $voyageExpense): JsonResponse
    {
        return $this->ok($this->res($this->expenses->approve($voyageExpense, $request->user())), 'Expense line approved.');
    }

    public function cancel(Request $request, VoyageExpense $voyageExpense): JsonResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);

        return $this->ok($this->res($this->expenses->cancel($voyageExpense, $request->user(), $d['reason'])), 'Expense line cancelled.');
    }

    public function destroy(Request $request, VoyageExpense $voyageExpense): JsonResponse
    {
        $this->expenses->delete($voyageExpense, $request->user());

        return $this->deleted('Expense line deleted.');
    }

    /** @return array<string, mixed> */
    private function rules(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'voyage_id' => ['nullable', 'integer', Rule::exists('voyages', 'id')],
            'contract_id' => ['nullable', 'integer', Rule::exists('contracts', 'id')],
            'expense_category_id' => [$req, 'integer', Rule::exists('expense_categories', 'id')],
            'description' => [$req, 'string', 'max:255'],
            'is_estimate' => ['nullable', 'boolean'],
            'quantity' => Rules::decimal(12, 4),
            'rate' => Rules::decimal(12, 4),
            'amount' => Rules::decimal(16, 2),
            'currency' => Rules::currency($creating),
            'supplier_company_id' => ['nullable', 'integer', Rule::exists('companies', 'id')],
            'incurred_at' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function res(VoyageExpense $expense): VoyageExpenseResource
    {
        return new VoyageExpenseResource($expense->load(self::WITH));
    }
}
