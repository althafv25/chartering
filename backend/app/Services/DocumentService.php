<?php

namespace App\Services;

use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Models\Document;
use App\Models\User;
use App\Support\ListQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Private document storage. Paths are always server-generated:
 *   {alias}/{parent_id}/{yyyy}/{mm}/{uuid}.{ext}
 * Files are only ever streamed through download() after authorization.
 */
class DocumentService
{
    /** @return array{model:class-string<Model>, view:string, upload:string, delete:string} */
    public function parentConfig(string $alias): array
    {
        $config = config("offshore.documents.parents.{$alias}");

        if (! is_array($config)) {
            throw new NotFoundHttpException("Documents are not supported for [{$alias}].");
        }

        return $config;
    }

    /** Attribute that identifies a parent record to a human (voyage number, invoice number, ...). */
    private const PARENT_LABEL = [
        'voyages' => 'voyage_number', 'contracts' => 'contract_number', 'invoices' => 'invoice_number', 'payables' => 'payable_number', 'payments' => 'payment_number',
        'vessels' => 'name', 'companies' => 'legal_name', 'ports' => 'name', 'offshore-locations' => 'name', 'users' => 'name', 'enquiries' => 'enquiry_number',
        'estimations' => 'estimation_number', 'offers' => 'offer_number', 'fixtures' => 'fixture_number', 'port-das' => 'da_number', 'offshore-activities' => 'activity_number',
        'bunker-stems' => 'stem_number',
    ];

    /**
     * Central register: documents of every record type the user may view (documents.view AND that type's own view permission),
     * newest first, with a human label for each parent record.
     *
     * @param  array<string, mixed>  $filters  search, parent_type, document_type_id, expiry (expired|30|60|90)
     * @return array{page: \Illuminate\Pagination\LengthAwarePaginator<int, Document>, parents: array<string, array<int, string>>}
     */
    public function register(User $actor, array $filters, int $perPage): array
    {
        if (! $actor->can(Permission::DocumentsView->value)) {
            throw new AuthorizationException('You do not have permission to perform this action.');
        }
        $aliases = collect(config('offshore.documents.parents'))->filter(fn (array $def) => $actor->can($def['view']))->keys()->all();
        if (! empty($filters['parent_type'])) {
            $aliases = array_values(array_intersect($aliases, [(string) $filters['parent_type']]));
        }

        $query = Document::query()->whereIn('documentable_type', $aliases)->with(['documentType', 'uploader'])
            ->when(! empty($filters['document_type_id']), fn ($q) => $q->where('document_type_id', (int) $filters['document_type_id']))
            ->when(! empty($filters['search']), function ($q) use ($filters) {
                $like = ListQuery::like((string) $filters['search']);
                $q->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('document_number', 'like', $like)->orWhere('original_filename', 'like', $like));
            });
        match ($filters['expiry'] ?? null) {
            'expired' => $query->whereDate('expiry_date', '<', today()),
            '30', '60', '90' => $query->whereDate('expiry_date', '>=', today())->whereDate('expiry_date', '<=', today()->addDays((int) $filters['expiry'])),
            default => null,
        };
        $page = $query->latest('id')->paginate($perPage);

        $parents = [];
        foreach ($page->getCollection()->groupBy('documentable_type') as $alias => $docs) {
            $column = self::PARENT_LABEL[$alias] ?? null;
            $model = $this->parentConfig((string) $alias)['model'];
            foreach ($model::query()->whereIn('id', $docs->pluck('documentable_id')->unique())->get() as $parent) {
                $parents[$alias][$parent->getKey()] = $column ? (string) $parent->getAttribute($column) : '#'.$parent->getKey();
            }
        }

        return ['page' => $page, 'parents' => $parents];
    }

    public function resolveParent(string $alias, int $id): Model
    {
        $model = $this->parentConfig($alias)['model'];

        return $model::query()->findOrFail($id);
    }

    public function parentPermission(string $alias, string $ability): string
    {
        return $this->parentConfig($alias)[$ability];
    }

    public function paginateFor(Model $parent, int $perPage): LengthAwarePaginator
    {
        return Document::query()
            ->where('documentable_type', $parent->getMorphClass())
            ->where('documentable_id', $parent->getKey())
            ->with(['documentType', 'uploader'])
            ->latest()
            ->paginate($perPage);
    }

    /** @param array<string, mixed> $meta */
    public function store(Model $parent, UploadedFile $file, array $meta, User $actor): Document
    {
        $disk = (string) config('offshore.documents.disk');
        $alias = $parent->getMorphClass();
        $extension = strtolower($file->getClientOriginalExtension() ?: ($file->guessExtension() ?? 'bin'));
        $directory = sprintf('%s/%d/%s', $alias, $parent->getKey(), now()->format('Y/m'));
        $filename = Str::uuid().'.'.$extension;

        $path = Storage::disk($disk)->putFileAs($directory, $file, $filename);
        if ($path === false) {
            throw new BusinessRuleException('The file could not be stored. Please try again.', 'storage_failed', status: 500);
        }

        try {
            return DB::transaction(fn () => Document::query()->create([
                'documentable_type' => $alias,
                'documentable_id' => $parent->getKey(),
                'document_type_id' => $meta['document_type_id'],
                'title' => $meta['title'] ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
                'document_number' => $meta['document_number'] ?? null,
                'issue_date' => $meta['issue_date'] ?? null,
                'expiry_date' => $meta['expiry_date'] ?? null,
                'disk' => $disk,
                'path' => $path,
                'original_filename' => mb_substr($this->safeName($file->getClientOriginalName()), 0, 255),
                'mime_type' => (string) $file->getMimeType(),
                'size_bytes' => (int) $file->getSize(),
                'sha256' => hash_file('sha256', $file->getRealPath()),
                'remarks' => $meta['remarks'] ?? null,
                'uploaded_by' => $actor->id,
            ])->load(['documentType', 'uploader']));
        } catch (\Throwable $e) {
            Storage::disk($disk)->delete($path); // no orphan files

            throw $e;
        }
    }

    public function download(Document $document): StreamedResponse
    {
        $storage = Storage::disk($document->disk);

        if (! $storage->exists($document->path)) {
            throw new NotFoundHttpException('The file is missing from storage.');
        }

        return $storage->download($document->path, $document->original_filename, [
            'Content-Type' => $document->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Soft delete keeps the file for audit/restore. */
    public function delete(Document $document): void
    {
        $document->delete();
    }

    private function safeName(string $name): string
    {
        $clean = preg_replace('/[^\pL\pN\.\-_ ]+/u', '_', $name) ?? 'file';

        return trim($clean) !== '' ? $clean : 'file';
    }
}
