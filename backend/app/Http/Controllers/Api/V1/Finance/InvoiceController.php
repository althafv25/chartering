<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Http\Resources\Finance\InvoiceResource;
use App\Models\Invoice;
use App\Services\Finance\InvoiceService;
use App\Support\Rules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InvoiceController extends Controller
{
    private const WITH_DETAIL = ['customer', 'contract', 'voyage', 'lines.taxCode', 'lines.voyageRevenue', 'creditNoteFor', 'creditNotes', 'allocations.payment'];

    public function __construct(private readonly InvoiceService $invoices) {}

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'customer_company_id' => ['nullable', 'integer'],
            'voyage_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(Invoice::STATUSES)],
            'invoice_type' => ['nullable', Rule::in(Invoice::TYPES)],
        ]);

        return $this->ok(InvoiceResource::collection(
            $this->invoices->paginate($request->user(), $f, $this->perPage($request))
        ));
    }

    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        return $this->ok(new InvoiceResource($this->invoices->find($request->user(), $invoice->id)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->rules($request, true);
        $invoice = $this->invoices->create($data, $request->user());

        return $this->created($this->res($invoice), 'Invoice created.');
    }

    public function update(Request $request, Invoice $invoice): JsonResponse
    {
        $data = $this->rules($request, false);

        return $this->ok($this->res($this->invoices->update($invoice, $data, $request->user())), 'Invoice updated.');
    }

    public function saveLines(Request $request, Invoice $invoice): JsonResponse
    {
        $d = $request->validate([
            'lines' => ['required', 'array', 'max:200'],
            'lines.*.voyage_revenue_id' => ['nullable', 'integer', Rule::exists('voyage_revenues', 'id')],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => Rules::decimal(12, 4),
            'lines.*.unit' => ['nullable', 'string', 'max:20'],
            'lines.*.rate' => Rules::decimal(12, 4),
            'lines.*.amount' => Rules::decimal(16, 2),
            'lines.*.tax_code_id' => ['nullable', 'integer', Rule::exists('tax_codes', 'id')],
        ]);

        return $this->ok($this->res($this->invoices->saveLines($invoice, $d['lines'], $request->user())), 'Invoice lines saved.');
    }

    public function attachRevenueLines(Request $request, Invoice $invoice): JsonResponse
    {
        $d = $request->validate([
            'revenue_ids' => ['required', 'array', 'min:1', 'max:200'],
            'revenue_ids.*' => ['integer', Rule::exists('voyage_revenues', 'id')],
        ]);

        return $this->ok($this->res($this->invoices->attachRevenueLines($invoice, $d['revenue_ids'], $request->user())), 'Revenue lines attached.');
    }

    public function submit(Request $request, Invoice $invoice): JsonResponse
    {
        return $this->ok($this->res($this->invoices->submit($invoice, $request->user())), 'Invoice submitted for approval.');
    }

    public function approve(Request $request, Invoice $invoice): JsonResponse
    {
        return $this->ok($this->res($this->invoices->approve($invoice, $request->user())), 'Invoice approved.');
    }

    public function reject(Request $request, Invoice $invoice): JsonResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);

        return $this->ok($this->res($this->invoices->reject($invoice, $request->user(), $d['reason'])), 'Invoice returned to draft.');
    }

    public function issue(Request $request, Invoice $invoice): JsonResponse
    {
        return $this->ok($this->res($this->invoices->issue($invoice, $request->user())), 'Invoice issued.');
    }

    public function cancel(Request $request, Invoice $invoice): JsonResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);

        return $this->ok($this->res($this->invoices->cancel($invoice, $request->user(), $d['reason'])), 'Invoice cancelled.');
    }

    public function creditNote(Request $request, Invoice $invoice): JsonResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $credit = $this->invoices->creditNote($invoice, $request->user(), $d['reason']);

        return $this->created($this->res($credit), "Credit note {$credit->invoice_number} issued.");
    }

    public function generatePdf(Request $request, Invoice $invoice): JsonResponse
    {
        $document = $this->invoices->generatePdf($invoice, $request->user());

        return $this->ok(['document_id' => $document->id, 'path' => $document->path], 'Invoice PDF generated.');
    }

    public function destroy(Request $request, Invoice $invoice): JsonResponse
    {
        $this->invoices->delete($invoice, $request->user());

        return $this->deleted('Invoice deleted.');
    }

    /** @return array<string, mixed> */
    private function rules(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'lock_version' => [$creating ? 'nullable' : 'required', 'integer'],
            'invoice_type' => [$req, Rule::in(Invoice::TYPES)],
            'customer_company_id' => [$req, 'integer', Rule::exists('companies', 'id')],
            'contract_id' => ['nullable', 'integer', Rule::exists('contracts', 'id')],
            'voyage_id' => ['nullable', 'integer', Rule::exists('voyages', 'id')],
            'issue_date' => [$req, 'date'],
            'due_date' => [$req, 'date', 'after_or_equal:issue_date'],
            'currency' => Rules::currency($creating),
            'remarks' => ['nullable', 'string', 'max:2000'],
            'revenue_ids' => ['nullable', 'array', 'max:200'],
            'revenue_ids.*' => ['integer', Rule::exists('voyage_revenues', 'id')],
        ]);
    }

    private function res(Invoice $invoice): InvoiceResource
    {
        return new InvoiceResource($invoice->load(self::WITH_DETAIL));
    }
}
