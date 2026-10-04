<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Services\Finance\BalancingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BalancingController extends Controller
{
    public function __construct(private readonly BalancingService $balancing) {}

    /** Account view: receivable/payable per company. */
    public function accounts(Request $request): JsonResponse
    {
        $f = $request->validate([
            'company_id' => ['nullable', 'integer'],
            'voyage_id' => ['nullable', 'integer'],
        ]);

        return $this->ok($this->balancing->accounts($request->user(), $f));
    }

    /** Calendar / cash-flow view: receivable/payable bucketed by due period. */
    public function cashFlow(Request $request): JsonResponse
    {
        $f = $request->validate([
            'company_id' => ['nullable', 'integer'],
            'voyage_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return $this->ok($this->balancing->cashFlow($request->user(), $f));
    }
}
