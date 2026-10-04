<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Http\Resources\Finance\PayableResource;
use App\Models\Payable;
use App\Services\Finance\PayableService;
use App\Support\Rules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PayableController extends Controller
{
    private const WITH_DETAIL = ['supplier', 'voyage', 'allocations.payment', 'voyageExpenses'];

    public function __construct(private readonly PayableService $payables) {}

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'supplier_company_id' => ['nullable', 'integer'],
            'voyage_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(Payable::STATUSES)],
        ]);

        return $this->ok(PayableResource::collection(
            $this->payables->paginate($request->user(), $f, $this->perPage($request))
        ));
    }

    public function show(Request $request, Payable $payable): JsonResponse
    {
        return $this->ok(new PayableResource($this->payables->find($request->user(), $payable->id)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->rules($request, true);
        $payable = $this->payables->create($data, $request->user());

        return $this->created($this->res($payable), "Payable {$payable->payable_number} created.");
    }

    public function update(Request $request, Payable $payable): JsonResponse
    {
        $data = $this->rules($request, false);

        return $this->ok($this->res($this->payables->update($payable, $data, $request->user())), 'Payable updated.');
    }

    public function approve(Request $request, Payable $payable): JsonResponse
    {
        return $this->ok($this->res($this->payables->approve($payable, $request->user())), 'Payable approved.');
    }

    public function reapprove(Request $request, Payable $payable): JsonResponse
    {
        return $this->ok($this->res($this->payables->reapprove($payable, $request->user())), 'Payable re-approved.');
    }

    public function cancel(Request $request, Payable $payable): JsonResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);

        return $this->ok($this->res($this->payables->cancel($payable, $request->user(), $d['reason'])), 'Payable cancelled.');
    }

    public function destroy(Request $request, Payable $payable): JsonResponse
    {
        $this->payables->delete($payable, $request->user());

        return $this->deleted('Payable deleted.');
    }

    /** @return array<string, mixed> */
    private function rules(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'lock_version' => [$creating ? 'nullable' : 'required', 'integer'],
            'supplier_company_id' => [$req, 'integer', Rule::exists('companies', 'id')],
            'supplier_invoice_ref' => [$req, 'string', 'max:100'],
            'voyage_id' => ['nullable', 'integer', Rule::exists('voyages', 'id')],
            'issue_date' => [$req, 'date'],
            'due_date' => [$req, 'date', 'after_or_equal:issue_date'],
            'currency' => Rules::currency($creating),
            'subtotal' => Rules::decimal(16, 2),
            'tax' => Rules::decimal(16, 2),
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function res(Payable $payable): PayableResource
    {
        return new PayableResource($payable->load(self::WITH_DETAIL));
    }
}
