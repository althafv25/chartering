<?php

namespace Tests\Feature\Chartering;

use App\Enums\UserRole;

class EnquiryTest extends CharteringTestCase
{
    public function test_create_numbered_enquiry_with_itinerary_and_shortlist(): void
    {
        $id = $this->enquiry();
        $this->getJson("/api/v1/enquiries/{$id}")->assertOk()
            ->assertJsonPath('data.enquiry_number', 'ENQ-'.now()->year.'-00001')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.quantity', '2000.000')
            ->assertJsonPath('data.ports.0.point.label', 'Jebel Ali (AEJEA)')
            ->assertJsonPath('data.ports.1.purpose', 'discharge');

        $this->postJson("/api/v1/enquiries/{$id}/vessels", ['vessel_id' => $this->vessel->id])->assertOk()
            ->assertJsonPath('data.vessels.0.shortlist_status', 'candidate');
        $this->postJson("/api/v1/enquiries/{$id}/vessels", ['vessel_id' => $this->vessel->id, 'shortlist_status' => 'selected'])->assertOk()
            ->assertJsonPath('data.vessels.0.shortlist_status', 'selected');
    }

    public function test_validation_and_optimistic_locking(): void
    {
        $this->as($this->charterer);
        $this->postJson('/api/v1/enquiries', ['business_type' => 'spaceship', 'laycan_from' => '2026-10-10', 'laycan_to' => '2026-10-01',
            'ports' => [['purpose' => 'load']]])->assertStatus(422)->assertJsonValidationErrors(['business_type', 'laycan_to', 'ports.0.port_id']);

        $id = $this->enquiry();
        $this->putJson("/api/v1/enquiries/{$id}", ['cargo_description' => 'x'])->assertStatus(422)->assertJsonValidationErrors('lock_version');
        $this->putJson("/api/v1/enquiries/{$id}", ['cargo_description' => 'Tubulars', 'lock_version' => 0])->assertOk()->assertJsonPath('data.lock_version', 1);
        $this->putJson("/api/v1/enquiries/{$id}", ['cargo_description' => 'Other', 'lock_version' => 0])->assertStatus(409)->assertJsonPath('error_code', 'stale_record');
    }

    public function test_status_lifecycle(): void
    {
        $id = $this->enquiry();
        $this->postJson("/api/v1/enquiries/{$id}/status", ['status' => 'fixed'])->assertStatus(409)->assertJsonPath('error_code', 'invalid_status_transition');
        $this->postJson("/api/v1/enquiries/{$id}/status", ['status' => 'lost'])->assertStatus(422)->assertJsonPath('error_code', 'reason_required');
        $this->postJson("/api/v1/enquiries/{$id}/status", ['status' => 'lost', 'reason' => 'Rate too high'])->assertOk()->assertJsonPath('data.status', 'lost');
        $this->putJson("/api/v1/enquiries/{$id}", ['remarks' => 'x', 'lock_version' => 1])->assertStatus(409)->assertJsonPath('error_code', 'enquiry_closed');
        $this->postJson('/api/v1/estimations', ['enquiry_id' => $id, 'vessel_id' => $this->vessel->id])->assertStatus(409)->assertJsonPath('error_code', 'enquiry_closed');
        $this->postJson("/api/v1/enquiries/{$id}/status", ['status' => 'open'])->assertOk()->assertJsonPath('data.status', 'open');
        $this->getJson("/api/v1/enquiries/{$id}/activity")->assertOk()->assertJsonPath('data.0.event', 'status_changed');
    }

    public function test_estimation_advances_enquiry_and_blocks_deletion(): void
    {
        $id = $this->enquiry();
        $this->estimation($id);
        $this->getJson("/api/v1/enquiries/{$id}")->assertJsonPath('data.status', 'evaluating')->assertJsonPath('data.vessels.0.vessel_id', $this->vessel->id);
        $this->deleteJson("/api/v1/enquiries/{$id}")->assertStatus(409)->assertJsonPath('error_code', 'enquiry_in_use');
        $this->deleteJson("/api/v1/enquiries/{$id}/vessels/{$this->vessel->id}")->assertStatus(409)->assertJsonPath('error_code', 'vessel_in_use');
    }

    public function test_permissions_and_filters(): void
    {
        $id = $this->enquiry();
        $this->getJson('/api/v1/enquiries?status=open&business_type=voyage_charter')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/enquiries?search=Gulf')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/enquiries/999999')->assertNotFound();

        $this->actingAsRole(UserRole::ReadOnly);
        $this->getJson("/api/v1/enquiries/{$id}")->assertOk();
        $this->postJson('/api/v1/enquiries', ['business_type' => 'voyage_charter'])->assertForbidden();
        $this->postJson("/api/v1/enquiries/{$id}/status", ['status' => 'cancelled'])->assertForbidden();

        $this->actingAsRole(UserRole::Accounts);
        $this->getJson('/api/v1/enquiries')->assertForbidden();
    }
}
