<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Document;
use App\Models\User;
use App\Services\DocumentService;
use Illuminate\Database\Eloquent\Model;

/**
 * Document access requires BOTH the document permission AND the configured
 * permission on the parent record (e.g. contracts.view for a contract file).
 */
class DocumentPolicy
{
    public function __construct(private readonly DocumentService $documents) {}

    public function viewAnyFor(User $actor, Model $parent): bool
    {
        return $actor->hasPermissionTo(Permission::DocumentsView->value)
            && $actor->can($this->documents->parentPermission($parent->getMorphClass(), 'view'));
    }

    public function createFor(User $actor, Model $parent): bool
    {
        return $actor->hasPermissionTo(Permission::DocumentsUpload->value)
            && $actor->can($this->documents->parentPermission($parent->getMorphClass(), 'upload'));
    }

    public function view(User $actor, Document $document): bool
    {
        return $actor->hasPermissionTo(Permission::DocumentsView->value)
            && $actor->can($this->documents->parentPermission($document->documentable_type, 'view'))
            && ($document->documentable_type !== 'contracts'
                || ! $document->documentType()->where('code', 'contract_pdf')->exists()
                || $actor->can(Permission::ContractsRatesView->value));
    }

    public function delete(User $actor, Document $document): bool
    {
        return $actor->hasPermissionTo(Permission::DocumentsDelete->value)
            && $actor->can($this->documents->parentPermission($document->documentable_type, 'delete'));
    }
}
