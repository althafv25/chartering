<?php

namespace App\Http\Controllers\Api\V1\Chartering;

use App\Http\Resources\AuditLogResource;
use App\Services\Chartering\ActivityFeed;
use Illuminate\Http\JsonResponse;

/** Shared "activity/history" tab for chartering records. */
trait ActivityResponder
{
    /** @param array<string, list<int>> $subjects */
    protected function activity(array $subjects): JsonResponse
    {
        return $this->ok(AuditLogResource::collection(app(ActivityFeed::class)->for($subjects, $this->perPage(request(), 25))));
    }
}
