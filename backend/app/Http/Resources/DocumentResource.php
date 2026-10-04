<?php

namespace App\Http\Resources;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Document */
class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'documentable_type' => $this->documentable_type,
            'documentable_id' => $this->documentable_id,
            'document_type' => $this->whenLoaded('documentType', fn () => [
                'id' => $this->documentType->id,
                'code' => $this->documentType->code,
                'name' => $this->documentType->name,
            ]),
            'title' => $this->title,
            'document_number' => $this->document_number,
            'issue_date' => $this->issue_date?->toDateString(),
            'expiry_date' => $this->expiry_date?->toDateString(),
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'remarks' => $this->remarks,
            'uploaded_by' => $this->whenLoaded('uploader', fn () => $this->uploader ? ['id' => $this->uploader->id, 'name' => $this->uploader->name] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
