<?php

namespace App\Http\Requests\Document;

use App\Models\DocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDocumentRequest extends FormRequest
{
    public function rules(): array
    {
        $extensions = implode(',', config('offshore.documents.extensions'));

        return [
            'file' => ['required', 'file', 'max:'.config('offshore.documents.max_kb'), 'extensions:'.$extensions],
            'document_type_id' => ['required', 'integer', Rule::exists('document_types', 'id')->where('status', 'active')],
            'title' => ['nullable', 'string', 'max:200'],
            'document_number' => ['nullable', 'string', 'max:100'],
            'issue_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function ($validator) {
            $type = DocumentType::query()->find($this->input('document_type_id'));
            if ($type?->requires_expiry && ! $this->filled('expiry_date')) {
                $validator->errors()->add('expiry_date', "An expiry date is required for {$type->name} documents.");
            }
        }];
    }
}
