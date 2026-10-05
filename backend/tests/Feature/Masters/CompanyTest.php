<?php

namespace Tests\Feature\Masters;

use App\Enums\UserRole;

class CompanyTest extends MastersTestCase
{
    public function test_create_with_roles_and_generated_code(): void
    {
        $this->actingAsRole(UserRole::Chartering);

        $this->postJson('/api/v1/companies', [
            'legal_name' => 'Gulf Marine Services LLC', 'roles' => ['owner', 'agent'], 'country' => 'ae',
            'email' => 'OPS@GMS.AE', 'credit_limit' => '250000.50', 'default_currency' => 'USD',
        ])->assertCreated()
            ->assertJsonPath('data.code', 'CMP-00001')
            ->assertJsonPath('data.roles', ['agent', 'owner'])
            ->assertJsonPath('data.country', 'AE')
            ->assertJsonPath('data.email', 'ops@gms.ae')
            ->assertJsonPath('data.credit_limit', '250000.50')
            ->assertJsonPath('data.status', 'active');
    }

    public function test_duplicate_detection_requires_confirmation(): void
    {
        $this->actingAsRole(UserRole::Chartering);
        $this->postJson('/api/v1/companies', ['legal_name' => 'Gulf Marine Services LLC', 'roles' => ['owner'], 'country' => 'AE'])->assertCreated();

        $this->postJson('/api/v1/companies', ['legal_name' => 'GULF MARINE SERVICES', 'roles' => ['charterer'], 'country' => 'AE'])
            ->assertStatus(409)->assertJsonPath('error_code', 'possible_duplicate')
            ->assertJsonPath('errors.duplicates.0', 'CMP-00001 — Gulf Marine Services LLC (AE)');

        $this->postJson('/api/v1/companies', ['legal_name' => 'GULF MARINE SERVICES', 'roles' => ['charterer'], 'country' => 'AE', 'confirm_duplicate' => true])
            ->assertCreated();
    }

    public function test_rename_keeps_former_name_as_searchable_alias(): void
    {
        $this->actingAsRole(UserRole::Commercial);
        $id = $this->postJson('/api/v1/companies', ['legal_name' => 'Old Name Shipping', 'roles' => ['charterer']])->json('data.id');

        $this->putJson("/api/v1/companies/{$id}", ['legal_name' => 'New Name Offshore', 'lock_version' => 0])
            ->assertOk()->assertJsonPath('data.aliases.0.alias', 'Old Name Shipping');

        $this->getJson('/api/v1/companies?search=Old Name')->assertOk()->assertJsonPath('data.0.legal_name', 'New Name Offshore');
        $this->getJson('/api/v1/companies?role=charterer')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/companies?role=agent')->assertJsonPath('meta.total', 0);
    }

    public function test_contacts_single_primary_and_search_by_contact(): void
    {
        $this->actingAsRole(UserRole::Operations);
        $c = $this->company(['agent']);

        $first = $this->postJson("/api/v1/companies/{$c->id}/contacts", ['first_name' => 'Ahmed', 'last_name' => 'Ali', 'email' => 'ahmed@agent.ae', 'is_primary' => true])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/companies/{$c->id}/contacts", ['first_name' => 'Sara', 'is_primary' => true])->assertCreated();

        $contacts = $this->getJson("/api/v1/companies/{$c->id}")->json('data.contacts');
        $this->assertCount(1, array_filter($contacts, fn ($x) => $x['is_primary']));
        $this->assertFalse(collect($contacts)->firstWhere('id', $first)['is_primary']);

        $this->getJson('/api/v1/companies?search=ahmed@agent')->assertJsonPath('meta.total', 1);
    }

    public function test_bank_details_are_masked_without_bank_permission(): void
    {
        $this->actingAsRole(UserRole::Finance);
        $c = $this->company(['supplier']);
        $this->postJson("/api/v1/companies/{$c->id}/bank-accounts", [
            'bank_name' => 'Emirates NBD', 'account_number' => '1012345678', 'iban' => 'AE07 0331 2345 6789 0123 456', 'swift_bic' => 'ebiladax',
        ])->assertCreated();

        $this->getJson("/api/v1/companies/{$c->id}")->assertJsonPath('data.bank_accounts.0.iban', 'AE070331234567890123456');

        $this->actingAsRole(UserRole::Chartering);
        $this->getJson("/api/v1/companies/{$c->id}")
            ->assertJsonPath('data.bank_accounts.0.account_number', '••••••5678')
            ->assertJsonPath('data.bank_accounts.0.masked', true);
        $this->postJson("/api/v1/companies/{$c->id}/bank-accounts", ['bank_name' => 'X'])->assertForbidden();
    }

    public function test_permissions_and_lookup(): void
    {
        $this->company(['charterer'], ['legal_name' => 'Aramco Offshore', 'normalized_name' => 'aramco offshore']);
        $this->company(['customer'], ['legal_name' => 'Customer Marine', 'normalized_name' => 'customer marine']);

        $this->actingAsRole(UserRole::ReadOnly);
        $this->getJson('/api/v1/companies/lookup?search=Aram&role=charterer')->assertOk()->assertJsonPath('data.0.legal_name', 'Aramco Offshore');
        $this->getJson('/api/v1/companies/lookup?search=Customer&role[]=charterer&role[]=customer')
            ->assertOk()->assertJsonPath('data.0.legal_name', 'Customer Marine');
        $this->postJson('/api/v1/companies', ['legal_name' => 'X', 'roles' => ['owner']])->assertForbidden();
    }
}
