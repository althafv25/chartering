<?php

namespace Tests\Feature\Core;

use App\Enums\UserRole;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        Storage::fake('documents');
    }

    private function otherTypeId(): int
    {
        return DocumentType::query()->where('code', 'other')->value('id');
    }

    public function test_upload_list_download_and_soft_delete(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);
        $owner = $this->userWithRole(UserRole::Chartering);

        $id = $this->post("/api/v1/users/{$owner->id}/documents", [
            'file' => UploadedFile::fake()->create('contract.pdf', 120, 'application/pdf'),
            'document_type_id' => $this->otherTypeId(),
            'title' => 'Signed contract',
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Signed contract')
            ->assertJsonMissingPath('data.path')
            ->json('data.id');

        $doc = Document::query()->findOrFail($id);
        $this->assertStringStartsWith("users/{$owner->id}/", $doc->path);
        Storage::disk('documents')->assertExists($doc->path);

        $this->getJson("/api/v1/users/{$owner->id}/documents")->assertOk()->assertJsonPath('meta.total', 1);

        $this->get("/api/v1/documents/{$id}/download")->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertDownload('contract.pdf');

        $this->deleteJson("/api/v1/documents/{$id}")->assertOk();
        $this->assertSoftDeleted('documents', ['id' => $id]);
    }

    public function test_download_requires_parent_permission(): void
    {
        $owner = $this->userWithRole(UserRole::Chartering);
        $this->actingAsRole(UserRole::SuperAdmin);
        $id = $this->post("/api/v1/users/{$owner->id}/documents", [
            'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
            'document_type_id' => $this->otherTypeId(),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        // Chartering has documents.view but not users.view → denied.
        $this->actingAsRole(UserRole::Chartering);
        $this->get("/api/v1/documents/{$id}/download", ['Accept' => 'application/json'])->assertForbidden();

        // Management has both → allowed.
        $this->actingAsRole(UserRole::Management);
        $this->get("/api/v1/documents/{$id}/download")->assertOk();
    }

    public function test_disallowed_extension_and_required_expiry(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);
        $owner = $this->userWithRole(UserRole::Chartering);
        $certificate = DocumentType::query()->where('code', 'certificate')->value('id');

        $this->post("/api/v1/users/{$owner->id}/documents", [
            'file' => UploadedFile::fake()->create('evil.php', 1, 'text/x-php'),
            'document_type_id' => $this->otherTypeId(),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('file');

        $this->post("/api/v1/users/{$owner->id}/documents", [
            'file' => UploadedFile::fake()->create('cert.pdf', 1, 'application/pdf'),
            'document_type_id' => $certificate,
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('expiry_date');
    }

    public function test_unknown_parent_type_is_404(): void
    {
        $this->actingAsRole(UserRole::SuperAdmin);

        $this->getJson('/api/v1/spaceships/1/documents')->assertNotFound();
    }

    public function test_upload_requires_upload_permission(): void
    {
        Role::findByName('read-only')->givePermissionTo('users.update');
        $owner = $this->userWithRole(UserRole::Chartering);
        $this->actingAsRole(UserRole::ReadOnly);

        $this->post("/api/v1/users/{$owner->id}/documents", [
            'file' => UploadedFile::fake()->create('a.pdf', 1, 'application/pdf'),
            'document_type_id' => $this->otherTypeId(),
        ], ['Accept' => 'application/json'])->assertForbidden();
    }
}
