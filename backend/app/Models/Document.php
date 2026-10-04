<?php

namespace App\Models;

use App\Models\Concerns\HasAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Private document attached to any registered parent (config offshore.documents.parents).
 *
 * @property int $id
 * @property string $documentable_type
 * @property int $documentable_id
 * @property string $disk
 * @property string $path
 * @property string $original_filename
 * @property string $mime_type
 * @property Carbon|null $issue_date
 * @property Carbon|null $expiry_date
 */
class Document extends Model
{
    use HasAuditLog, SoftDeletes;

    protected $fillable = [
        'documentable_type', 'documentable_id', 'document_type_id', 'title', 'document_number',
        'issue_date', 'expiry_date', 'disk', 'path', 'original_filename', 'mime_type',
        'size_bytes', 'sha256', 'remarks', 'uploaded_by',
    ];

    /** @var list<string> */
    protected $hidden = ['disk', 'path'];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'expiry_date' => 'date',
            'size_bytes' => 'integer',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<DocumentType, $this> */
    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    protected function auditExcept(): array
    {
        return ['disk', 'path', 'sha256'];
    }
}
