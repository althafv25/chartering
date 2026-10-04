<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize(Permission::AuditLogsView->value);

        $f = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'log_name' => ['nullable', 'string', 'max:60'],
            'event' => ['nullable', 'string', 'max:40'],
            'subject_type' => ['nullable', 'string', 'max:60'],
            'subject_id' => ['nullable', 'integer'],
            'causer_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $logs = Activity::query()->with('causer')
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where('description', 'like', "%{$s}%"))
            ->when($f['log_name'] ?? null, fn ($q, $v) => $q->where('log_name', $v))
            ->when($f['event'] ?? null, fn ($q, $v) => $q->where('event', $v))
            ->when($f['subject_type'] ?? null, fn ($q, $v) => $q->where('subject_type', $v))
            ->when($f['subject_id'] ?? null, fn ($q, $v) => $q->where('subject_id', $v))
            ->when($f['causer_id'] ?? null, fn ($q, $v) => $q->where('causer_id', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<', now()->parse($v)->addDay()->startOfDay()))
            ->latest('id')
            ->paginate($this->perPage($request, 25));

        return $this->ok(AuditLogResource::collection($logs));
    }

    public function filters(): JsonResponse
    {
        $this->authorize(Permission::AuditLogsView->value);

        return $this->ok([
            'log_names' => Activity::query()->distinct()->orderBy('log_name')->pluck('log_name')->filter()->values(),
            'events' => Activity::query()->distinct()->orderBy('event')->pluck('event')->filter()->values(),
        ]);
    }
}
