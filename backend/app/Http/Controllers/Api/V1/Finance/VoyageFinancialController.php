<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Models\Voyage;
use App\Services\Finance\VoyageFinancialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VoyageFinancialController extends Controller
{
    public function __construct(private readonly VoyageFinancialService $financials) {}

    public function show(Request $request, Voyage $voyage): JsonResponse
    {
        $this->authorize('view', $voyage);

        return $this->ok($this->financials->forVoyage($request->user(), $voyage));
    }
}
