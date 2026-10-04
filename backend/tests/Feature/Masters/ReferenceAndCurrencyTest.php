<?php

namespace Tests\Feature\Masters;

use App\Enums\UserRole;
use App\Models\ExpenseCategory;
use App\Models\FuelType;
use App\Models\Vessel;

class ReferenceAndCurrencyTest extends MastersTestCase
{
    public function test_reference_crud_with_registry_fields(): void
    {
        $this->actingAsRole(UserRole::Operations);

        $this->getJson('/api/v1/reference')->assertOk()->assertJsonPath('data.fuel-types.fields.category.type', 'select');
        $id = $this->postJson('/api/v1/reference/fuel-types', ['code' => 'b30', 'name' => 'B30 Biofuel', 'category' => 'OTHER', 'is_eca_compliant' => true])
            ->assertCreated()->assertJsonPath('data.code', 'B30')->json('data.id');
        $this->postJson('/api/v1/reference/fuel-types', ['code' => 'B30', 'name' => 'Dup', 'category' => 'OTHER'])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/reference/fuel-types', ['code' => 'X', 'name' => 'X', 'category' => 'COAL'])->assertStatus(422)->assertJsonValidationErrors('category');
        $this->putJson("/api/v1/reference/fuel-types/{$id}", ['status' => 'inactive'])->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->deleteJson("/api/v1/reference/fuel-types/{$id}")->assertOk();
        $this->getJson('/api/v1/reference/spaceship-types')->assertNotFound();
    }

    public function test_reference_in_use_cannot_be_deleted(): void
    {
        $this->actingAsRole(UserRole::Operations);
        $port = ExpenseCategory::query()->where('code', 'PORT')->value('id');
        $this->deleteJson("/api/v1/reference/expense-categories/{$port}")->assertStatus(409)->assertJsonPath('error_code', 'reference_in_use');

        Vessel::query()->create(['code' => 'V1', 'name' => 'V1', 'vessel_type_id' => $this->psvTypeId()]);
        $this->deleteJson('/api/v1/reference/vessel-types/'.$this->psvTypeId())->assertStatus(409);
    }

    public function test_non_master_users_see_only_active_values_and_cannot_edit(): void
    {
        FuelType::query()->where('code', 'HFO')->update(['status' => 'inactive']);
        $this->actingAsRole(UserRole::ReadOnly); // has masters.view
        $this->assertContains('HFO', collect($this->getJson('/api/v1/reference/fuel-types')->json('data'))->pluck('code'));
        $this->postJson('/api/v1/reference/fuel-types', ['code' => 'X', 'name' => 'X', 'category' => 'OTHER'])->assertForbidden();
    }

    public function test_vessel_type_attribute_schema(): void
    {
        $this->actingAsRole(UserRole::MarineOperations);
        $id = $this->psvTypeId();

        $this->putJson("/api/v1/reference/vessel-types/{$id}/attributes", ['attribute_schema' => [
            ['key' => 'mud_tanks', 'label' => 'Mud tanks', 'data_type' => 'integer'],
            ['key' => 'class', 'label' => 'Class', 'data_type' => 'select'],
        ]])->assertStatus(422)->assertJsonValidationErrors('attribute_schema.1.options');

        $this->putJson("/api/v1/reference/vessel-types/{$id}/attributes", ['attribute_schema' => [
            ['key' => 'mud_tanks', 'label' => 'Mud tanks', 'data_type' => 'integer'],
        ]])->assertOk()->assertJsonPath('data.attribute_schema.0.key', 'mud_tanks');
    }

    public function test_currencies_and_exchange_rates(): void
    {
        $this->actingAsRole(UserRole::Finance);

        $this->getJson('/api/v1/currencies')->assertOk()->assertJsonFragment(['code' => 'USD', 'is_base' => true]);
        $this->postJson('/api/v1/currencies', ['code' => 'nok', 'name' => 'Norwegian Krone', 'decimals' => 2])->assertCreated()->assertJsonPath('data.code', 'NOK');
        $usd = $this->getJson('/api/v1/currencies')->collect('data')->firstWhere('code', 'USD')['id'];
        $this->putJson("/api/v1/currencies/{$usd}", ['status' => 'inactive'])->assertStatus(409)->assertJsonPath('error_code', 'base_currency_locked');

        $this->postJson('/api/v1/exchange-rates', ['rate_date' => '2026-10-01', 'base_currency' => 'usd', 'quote_currency' => 'aed', 'rate' => '3.6725'])
            ->assertCreated()->assertJsonPath('data.rate', '3.67250000');
        $this->postJson('/api/v1/exchange-rates', ['rate_date' => '2026-10-01', 'base_currency' => 'USD', 'quote_currency' => 'AED', 'rate' => '3.67'])
            ->assertStatus(422)->assertJsonValidationErrors('quote_currency');
        $this->postJson('/api/v1/exchange-rates', ['rate_date' => '2026-10-01', 'base_currency' => 'USD', 'quote_currency' => 'USD', 'rate' => '1'])
            ->assertStatus(422)->assertJsonValidationErrors('quote_currency');

        $this->getJson('/api/v1/exchange-rates/convert?from=AED&to=USD&date=2026-10-02&amount=1000')
            ->assertOk()->assertJsonPath('data.rate', '0.27229408')->assertJsonPath('data.converted_amount', '272.29')->assertJsonMissingPath('data.raw');
        $this->getJson('/api/v1/exchange-rates/convert?from=GBP&to=USD&date=2026-10-02')
            ->assertStatus(422)->assertJsonPath('error_code', 'exchange_rate_missing');

        $this->actingAsRole(UserRole::Chartering);
        $this->getJson('/api/v1/exchange-rates')->assertOk();
        $this->postJson('/api/v1/exchange-rates', ['rate_date' => '2026-10-02', 'base_currency' => 'USD', 'quote_currency' => 'EUR', 'rate' => '0.9'])->assertForbidden();
    }
}
