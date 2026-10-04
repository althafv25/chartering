<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Activitylog\Models\Activity;

/** @mixin Activity */
class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Entries written by other tools or older imports may have no properties; the audit page must still load.
        $props = $this->properties ?? collect();

        return [
            'id' => $this->id,
            'log_name' => $this->log_name,
            'event' => $this->event,
            'description' => $this->description,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'causer' => $this->causer ? ['id' => $this->causer->getKey(), 'name' => $this->causer->getAttribute('name')] : null,
            'old' => $props->get('old'),
            'new' => $props->get('attributes'),
            'ip' => $props->get('ip'),
            'user_agent' => $props->get('user_agent'),
            'request_id' => $props->get('request_id'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
