<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Http\Resources\Finance\PaymentResource;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Services\Finance\PaymentService;
use App\Support\Rules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    private const WITH_DETAIL = ['company', 'allocations.invoice', 'allocations.payable'];

    public function __construct(private readonly PaymentService $payments) {}

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'company_id' => ['nullable', 'integer'],
            'direction' => ['nullable', Rule::in(['received', 'paid'])],
            'status' => ['nullable', Rule::in(['recorded', 'partially_allocated', 'allocated', 'partially_paid', 'paid', 'reversed'])],
        ]);

        return $this->ok(PaymentResource::collection(
            $this->payments->paginate($request->user(), $f, $this->perPage($request))
        ));
    }

    public function show(Request $request, Payment $payment): JsonResponse
    {
        return $this->ok(new PaymentResource($this->payments->find($request->user(), $payment->id)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'direction' => ['required', Rule::in(['received', 'paid'])],
            'company_id' => ['required', 'integer', Rule::exists('companies', 'id')],
            'payment_date' => ['required', 'date'],
            'amount' => Rules::decimal(16, 2, true),
            'currency' => Rules::currency(true),
            'bank_account_ref' => ['nullable', 'string', 'max:100'],
            'bank_reference' => ['nullable', 'string', 'max:100'],
            'method' => ['nullable', Rule::in(Payment::METHODS)],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'confirm_duplicate' => ['nullable', 'boolean'],
        ]);
        $payment = $this->payments->create($data, $request->user());

        return $this->created($this->res($payment), "Payment {$payment->payment_number} recorded.");
    }

    public function allocate(Request $request, Payment $payment): JsonResponse
    {
        $d = $request->validate([
            'allocations' => ['required', 'array', 'min:1', 'max:200'],
            'allocations.*.invoice_id' => ['nullable', 'integer', 'required_without:allocations.*.payable_id', Rule::exists('invoices', 'id')],
            'allocations.*.payable_id' => ['nullable', 'integer', 'required_without:allocations.*.invoice_id', Rule::exists('payables', 'id')],
            'allocations.*.amount' => Rules::decimal(16, 2, true),
        ]);
        $this->payments->allocate($payment, $d['allocations'], $request->user());

        return $this->ok($this->res($payment->refresh()), 'Payment allocated.');
    }

    public function unallocate(Request $request, Payment $payment, PaymentAllocation $allocation): JsonResponse
    {
        return $this->ok($this->res($this->payments->unallocate($payment, $allocation, $request->user())), 'Allocation removed.');
    }

    public function reverse(Request $request, Payment $payment): JsonResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);

        return $this->ok($this->res($this->payments->reverse($payment, $request->user(), $d['reason'])), 'Payment reversed.');
    }

    public function destroy(Request $request, Payment $payment): JsonResponse
    {
        $this->payments->delete($payment, $request->user());

        return $this->deleted('Payment deleted.');
    }

    private function res(Payment $payment): PaymentResource
    {
        return new PaymentResource($payment->load(self::WITH_DETAIL));
    }
}
