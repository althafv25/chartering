<?php

namespace App\Http\Resources\Masters;

use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Contact */
class ContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->fullName(),
            'job_title' => $this->job_title,
            'department' => $this->department,
            'email' => $this->email,
            'phone' => $this->phone,
            'mobile' => $this->mobile,
            'is_primary' => $this->is_primary,
            'remarks' => $this->remarks,
        ];
    }
}
