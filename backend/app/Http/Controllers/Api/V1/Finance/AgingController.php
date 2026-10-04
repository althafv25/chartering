<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Services\Finance\AgingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgingController extends Controller
{
    public function __construct(private readonly AgingService $aging) {}

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'as_of' => ['nullable', 'date'],
            'customer_company_id' => ['nullable', 'integer'],
        ]);

        // The overview carries no per-invoice rows (they can number in the tens of thousands); ask for one customer to get its invoices.
        $customerId = isset($f['customer_company_id']) ? (int) $f['customer_company_id'] : null;

        return $this->ok($this->aging->report($request->user(), $f['as_of'] ?? null, $customerId, withInvoices: $customerId !== null));
    }
}
