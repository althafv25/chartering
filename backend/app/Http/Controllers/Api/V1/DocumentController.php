<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Document\StoreDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Models\DocumentType;
use App\Services\DocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function __construct(private readonly DocumentService $documents) {}

    public function types(): JsonResponse
    {
        return $this->ok(DocumentType::query()->where('status', 'active')->orderBy('name')->get(['id', 'code', 'name', 'requires_expiry']));
    }

    /** Central register across all record types the user may view. */
    public function register(Request $request): JsonResponse
    {
        $f = $request->validate([
            'search' => ['nullable', 'string', 'max:100'], 'parent_type' => ['nullable', 'string', 'max:40'], 'document_type_id' => ['nullable', 'integer'],
            'expiry' => ['nullable', 'in:expired,30,60,90'],
        ]);
        ['page' => $page, 'parents' => $parents] = $this->documents->register($request->user(), $f, $this->perPage($request));

        $items = $page->getCollection()->map(fn (Document $d) => [
            ...(new DocumentResource($d))->resolve(),
            'parent' => ['type' => $d->documentable_type, 'id' => $d->documentable_id, 'label' => $parents[$d->documentable_type][$d->documentable_id] ?? '#'.$d->documentable_id],
        ])->all();

        return $this->page($page, $items);
    }

    public function index(Request $request, string $parentType, int $parentId): JsonResponse
    {
        $parent = $this->documents->resolveParent($parentType, $parentId);
        $this->authorize('viewAnyFor', [Document::class, $parent]);

        return $this->ok(DocumentResource::collection($this->documents->paginateFor($parent, $this->perPage($request))));
    }

    public function store(StoreDocumentRequest $request, string $parentType, int $parentId): JsonResponse
    {
        $parent = $this->documents->resolveParent($parentType, $parentId);
        $this->authorize('createFor', [Document::class, $parent]);

        $document = $this->documents->store($parent, $request->file('file'), $request->safe()->except('file'), $request->user());

        return $this->created(new DocumentResource($document), 'Document uploaded.');
    }

    public function show(Document $document): JsonResponse
    {
        $this->authorize('view', $document);

        return $this->ok(new DocumentResource($document->load(['documentType', 'uploader'])));
    }

    public function download(Document $document): StreamedResponse
    {
        $this->authorize('view', $document);

        activity('documents')->performedOn($document)->causedBy(request()->user())->event('downloaded')->log('Document downloaded');

        return $this->documents->download($document);
    }

    public function destroy(Document $document): JsonResponse
    {
        $this->authorize('delete', $document);
        $this->documents->delete($document);

        return $this->deleted('Document deleted.');
    }
}
