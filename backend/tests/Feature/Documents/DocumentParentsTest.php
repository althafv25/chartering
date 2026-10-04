<?php

namespace Tests\Feature\Documents;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\PortDa;
use App\Models\Voyage;
use Illuminate\Database\Eloquent\Relations\Relation;
use Tests\Feature\Operations\OperationsTestCase;

/**
 * Every record type that shows a Documents tab must be a registered document parent: its alias maps to the
 * model (morph map) and its permissions exist. The frontend passes these aliases as the URL segment.
 */
class DocumentParentsTest extends OperationsTestCase
{
    public function test_every_configured_parent_is_consistent(): void
    {
        $permissions = Permission::values();
        foreach (config('offshore.documents.parents') as $alias => $def) {
            $this->assertSame($def['model'], Relation::getMorphedModel($alias), "{$alias}: morph alias must map to its model");
            foreach (['view', 'upload', 'delete'] as $ability) {
                $this->assertContains($def[$ability], $permissions, "{$alias}: unknown {$ability} permission {$def[$ability]}");
            }
        }
    }

    /** Aliases used by frontend DocumentsPanel calls (grep parentType=). */
    public function test_pages_with_a_documents_tab_are_supported(): void
    {
        foreach (['vessels', 'companies', 'ports', 'enquiries', 'estimations', 'offers', 'fixtures', 'contracts', 'voyages', 'invoices', 'payables', 'payments', 'port-das', 'laytime-calculations'] as $alias) {
            $this->assertArrayHasKey($alias, config('offshore.documents.parents'), "{$alias} is used by a Documents tab but is not a document parent");
        }
    }

    public function test_documents_can_be_listed_for_a_port_da(): void
    {
        $voyage = $this->sailingVoyage();
        $call = $this->portCall($voyage);
        $da = PortDa::query()->create(['da_number' => 'DA-D-1', 'port_call_id' => $call['id'], 'voyage_id' => $voyage, 'port_id' => $this->ports[0], 'da_type' => 'proforma', 'currency' => 'USD']);

        $this->as($this->ops)->getJson("/api/v1/port-das/{$da->id}/documents")->assertOk();
        $this->as($this->userWithRole(UserRole::Finance))->getJson("/api/v1/port-das/{$da->id}/documents")->assertOk();
    }

    private function document(string $type, int $id, string $title, ?string $expiry = null): Document
    {
        return Document::query()->create([
            'documentable_type' => $type, 'documentable_id' => $id, 'document_type_id' => DocumentType::query()->value('id'), 'title' => $title, 'expiry_date' => $expiry,
            'disk' => 'documents', 'path' => "{$type}/{$id}/{$title}.pdf", 'original_filename' => "{$title}.pdf", 'mime_type' => 'application/pdf', 'size_bytes' => 10, 'sha256' => hash('sha256', $title),
        ]);
    }

    public function test_register_lists_only_record_types_the_user_may_view_with_parent_labels(): void
    {
        $voyage = $this->voyage();
        $number = Voyage::query()->findOrFail($voyage)->voyage_number;
        $this->document('voyages', $voyage, 'Charter party');
        $this->document('users', $this->ops->id, 'Passport copy');   // users.view is needed to see these

        $finance = $this->userWithRole(UserRole::Finance);
        $rows = $this->as($finance)->getJson('/api/v1/documents')->assertOk()->json('data');
        $this->assertSame(['Charter party'], array_column($rows, 'title'));
        $this->assertSame(['type' => 'voyages', 'id' => $voyage, 'label' => $number], $rows[0]['parent']);

        $management = $this->userWithRole(UserRole::Management);
        $this->assertEqualsCanonicalizing(['Charter party', 'Passport copy'], array_column($this->as($management)->getJson('/api/v1/documents')->json('data'), 'title'));
        // Asking for a type you may not view returns nothing rather than leaking it.
        $this->assertSame([], $this->as($finance)->getJson('/api/v1/documents?parent_type=users')->json('data'));
    }

    public function test_register_filters_by_search_type_and_expiry(): void
    {
        $voyage = $this->voyage();
        $this->document('voyages', $voyage, 'Insurance certificate', now()->subDays(3)->toDateString());
        $this->document('voyages', $voyage, 'Class certificate', now()->addDays(20)->toDateString());
        $this->document('voyages', $voyage, 'Bill of lading');
        $finance = $this->userWithRole(UserRole::Finance);

        $titles = fn (string $query) => array_column($this->as($finance)->getJson("/api/v1/documents{$query}")->assertOk()->json('data'), 'title');
        $this->assertSame(['Insurance certificate'], $titles('?search=insurance'));
        $this->assertSame(['Insurance certificate'], $titles('?expiry=expired'));
        $this->assertSame(['Class certificate'], $titles('?expiry=30'));
        $this->assertCount(3, $titles('?parent_type=voyages'));
        $this->as($finance)->getJson('/api/v1/documents?expiry=soon')->assertStatus(422);
    }
}
