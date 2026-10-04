<?php

namespace App\Http\Controllers;

use App\Http\Concerns\ApiResponse;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

abstract class Controller
{
    use ApiResponse, AuthorizesRequests;

    /** Bounded page size for list endpoints. */
    protected function perPage(Request $request, int $default = 15): int
    {
        return max(1, min(100, (int) $request->integer('per_page', $default)));
    }
}
