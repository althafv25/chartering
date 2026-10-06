<?php

namespace Tests\Feature\Chartering;

use App\Enums\UserRole;
use App\Models\Contract;
use App\Models\Document;
use App\Models\Offer;
use App\Models\OfferRevision;
use App\Models\User;
use App\Services\DocumentService;
use App\Services\SettingsService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Contracts\ContractTestCase;

class CommercialPdfTest extends ContractTestCase
{
    private string $html = '';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        config(['offshore.documents.disk' => 'documents']);
    }

    /** Capture the rendered HTML while still rendering a real PDF with DomPDF. */
    private function capturePdf(): void
    {
        $renderer = app('dompdf.wrapper');
        Pdf::shouldReceive('loadView')->andReturnUsing(function ($view, $data) use ($renderer) {
            $this->html = view($view, $data)->render();

            return $renderer->loadHTML($this->html);
        });
    }

    /** @return array{0:Offer,1:OfferRevision} */
    private function draftOffer(): array
    {
        $enquiry = $this->enquiry();
        $id = $this->postJson('/api/v1/offers', ['enquiry_id' => $enquiry, 'vessel_id' => $this->vessel->id])
            ->assertCreated()->json('data.id');
        $offer = Offer::query()->findOrFail($id);

        return [$offer, $offer->revisions()->firstOrFail()];
    }

    private function assertStoredPdf(int $id, string $alias, int $parentId): Document
    {
        $document = Document::query()->findOrFail($id);
        $this->assertSame($alias, $document->documentable_type);
        $this->assertSame($parentId, $document->documentable_id);
        $binary = Storage::disk('documents')->get($document->path);
        $this->assertStringStartsWith('%PDF-', $binary);
        $this->assertGreaterThan(1000, strlen($binary));
        $this->assertSame(hash('sha256', $binary), $document->getAttribute('sha256'));
        $this->get("/api/v1/documents/{$id}/download")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->getJson("/api/v1/{$alias}/{$parentId}/documents")->assertOk()->assertJsonFragment(['id' => $id]);
        $this->assertTrue(Activity::query()->where('subject_type', $alias)->where('subject_id', $parentId)->where('event', 'pdf_generated')->exists());

        return $document;
    }

    public function test_offer_pdf_uses_the_requested_revision_and_escapes_terms_without_changing_workflow(): void
    {
        [$offer, $revision] = $this->draftOffer();
        $revision->update(['rate' => '56.1250', 'terms' => "Net 30 days\n<script>alert('test')</script>", 'remarks' => 'PRIVATE NEGOTIATION NOTES']);
        $this->postJson("/api/v1/offers/{$offer->id}/revisions/{$revision->id}/send")->assertOk();
        $this->postJson("/api/v1/offers/{$offer->id}/revisions", ['rate' => '999.75'])->assertCreated();
        app(SettingsService::class)->update(['company.name' => 'Northstar Marine', 'company.email' => 'desk@example.test'], $this->charterer);
        $before = $revision->refresh()->getAttributes();
        $this->capturePdf();

        $id = $this->postJson("/api/v1/offers/{$offer->id}/revisions/{$revision->id}/pdf")->assertCreated()
            ->assertJsonPath('data.original_filename', $offer->offer_number.'-R1.pdf')
            ->assertJsonPath('data.document_type.code', 'offer_pdf')->json('data.id');

        $this->assertStringContainsString('56.125 USD', $this->html);
        $this->assertStringNotContainsString('999.75 USD', $this->html);
        $this->assertStringContainsString('Northstar Marine', $this->html);
        $this->assertStringContainsString('desk@example.test', $this->html);
        $this->assertStringContainsString('&lt;script&gt;', $this->html);
        $this->assertStringNotContainsString('<script>', $this->html);
        $this->assertStringNotContainsString('PRIVATE NEGOTIATION NOTES', $this->html);
        $this->assertSame($before, $revision->refresh()->getAttributes());
        $this->assertStoredPdf($id, 'offers', $offer->id);
    }

    public function test_fixture_recap_contains_agreed_terms_without_internal_estimate_financials(): void
    {
        $id = $this->approvedFixture();
        $this->capturePdf();
        $document = $this->postJson("/api/v1/fixtures/{$id}/pdf")->assertCreated()->json('data.id');

        $this->assertStringContainsString('Fixture recap', $this->html);
        $this->assertStringContainsString('Gulf Charterers', $this->html);
        $this->assertStringContainsString('Jebel Ali', $this->html);
        $this->assertStringContainsString('55 USD', $this->html);
        foreach (['Estimated profit', 'TCE', 'Break-even', 'inputs_hash', 'Calculation version'] as $private) {
            $this->assertStringNotContainsString($private, $this->html);
        }
        $this->assertStoredPdf($document, 'fixtures', $id);
    }

    public function test_contract_pdf_uses_current_version_rates_clauses_and_frozen_header(): void
    {
        $id = $this->approvedContract();
        $contract = Contract::query()->findOrFail($id);
        $contract->rates()->create(['version_no' => 2, 'rate_type' => 'lump_sum', 'description' => 'Revised agreed freight', 'amount' => '6543.21', 'currency' => 'USD', 'unit' => 'lump_sum']);
        $contract->clauses()->create(['version_no' => 1, 'sequence' => 1, 'title' => 'Obsolete clause', 'body' => 'Obsolete wording']);
        $contract->clauses()->create(['version_no' => 2, 'sequence' => 1, 'title' => 'Updated payment clause', 'body' => 'Payment & delivery terms.']);
        $contract->versions()->create(['version_no' => 2, 'effective_from' => '2027-01-01', 'header_snapshot' => [
            'title' => 'Agreed version two', 'end_date' => '2027-12-31', 'terms' => 'Frozen version two terms',
        ]]);
        $contract->update(['current_version' => 2, 'title' => 'Live title must not replace snapshot', 'remarks' => 'PRIVATE CONTRACT NOTES']);
        $before = $contract->refresh()->getAttributes();
        $this->capturePdf();

        $document = $this->postJson("/api/v1/contracts/{$id}/pdf")->assertCreated()
            ->assertJsonPath('data.original_filename', $contract->contract_number.'-v2.pdf')->json('data.id');
        foreach (['Agreed version two', 'Frozen version two terms', '6,543.21 USD', 'Updated payment clause', '31 Dec 2027', 'Authorized signature'] as $text) {
            $this->assertStringContainsString($text, $this->html);
        }
        foreach (['Obsolete clause', '55 USD', 'Live title must not replace snapshot', 'PRIVATE CONTRACT NOTES'] as $text) {
            $this->assertStringNotContainsString($text, $this->html);
        }
        $this->assertSame($before, $contract->refresh()->getAttributes());
        $this->assertStoredPdf($document, 'contracts', $id);
    }

    public function test_estimation_pdf_includes_saved_assumptions_and_current_results(): void
    {
        [$estimation, $scenario] = $this->approvedEstimation();
        $this->capturePdf();
        $document = $this->postJson("/api/v1/estimations/{$estimation}/scenarios/{$scenario}/pdf")->assertCreated()
            ->assertJsonPath('data.document_type.code', 'estimation_pdf')->json('data.id');
        foreach (['Internal estimation report', 'Calculated financial summary', 'Profit', 'TCE / day', 'Consumption snapshot', 'Bunker prices', 'Revenue items', 'Jebel Ali', '800'] as $text) {
            $this->assertStringContainsString($text, $this->html);
        }
        $this->assertStringNotContainsString('No current calculated results', $this->html);
        $this->assertStoredPdf($document, 'estimations', $estimation);
    }

    public function test_uncalculated_estimation_pdf_is_explicit_and_generation_does_not_calculate_it(): void
    {
        [$estimation, $scenario] = $this->estimation();
        $before = $this->getJson("/api/v1/estimations/{$estimation}/scenarios/{$scenario}")->json('data');
        $this->capturePdf();
        $document = $this->postJson("/api/v1/estimations/{$estimation}/scenarios/{$scenario}/pdf")->assertCreated()->json('data.id');
        $this->assertStringContainsString('No current calculated results', $this->html);
        $this->assertStringNotContainsString('Calculated financial summary', $this->html);
        $this->assertSame($before, $this->getJson("/api/v1/estimations/{$estimation}/scenarios/{$scenario}")->json('data'));
        $this->assertStoredPdf($document, 'estimations', $estimation);
    }

    public function test_pdf_generation_requires_document_and_parent_permissions_and_scoped_children(): void
    {
        [$offer, $revision] = $this->draftOffer();
        [$other, $otherRevision] = $this->draftOffer();
        $this->postJson("/api/v1/offers/{$offer->id}/revisions/{$otherRevision->id}/pdf")->assertNotFound();
        [$estimation] = $this->estimation();
        [$otherEstimation, $otherScenario] = $this->estimation();
        $this->postJson("/api/v1/estimations/{$estimation}/scenarios/{$otherScenario}/pdf")->assertNotFound();

        $this->actingAsRole(UserRole::ReadOnly);
        $this->postJson("/api/v1/offers/{$offer->id}/revisions/{$revision->id}/pdf")->assertForbidden();
        $this->postJson("/api/v1/estimations/{$otherEstimation}/scenarios/{$otherScenario}/pdf")->assertForbidden();
        $missingView = User::factory()->create();
        $missingView->givePermissionTo(['documents.view', 'documents.upload', 'chartering.offers.update']);
        $this->as($missingView)->postJson("/api/v1/offers/{$other->id}/revisions/{$otherRevision->id}/pdf")->assertForbidden();
        $this->assertSame(0, Document::query()->count());
    }

    public function test_contract_rate_permission_is_required_for_generation_and_later_download(): void
    {
        $id = $this->approvedContract();
        $document = $this->postJson("/api/v1/contracts/{$id}/pdf")->assertCreated()->json('data.id');
        $user = User::factory()->create();
        $user->givePermissionTo(['documents.view', 'documents.upload', 'contracts.view', 'contracts.update']);
        $this->as($user)->postJson("/api/v1/contracts/{$id}/pdf")->assertForbidden();
        $this->getJson("/api/v1/documents/{$document}/download")->assertForbidden();
        $user->givePermissionTo('contracts.rates.view');
        $this->as($user)->get("/api/v1/documents/{$document}/download")->assertOk();
    }

    public function test_failed_document_record_creation_cleans_up_the_generated_file(): void
    {
        [$offer] = $this->draftOffer();
        try {
            app(DocumentService::class)->storeGeneratedPdf($offer, '%PDF-test', [
                'document_type_id' => 999999, 'title' => 'Test', 'document_number' => 'Test', 'original_filename' => 'test.pdf',
            ], $this->charterer);
            $this->fail('The invalid document type must fail.');
        } catch (QueryException $e) {
            $this->assertSame([], Storage::disk('documents')->allFiles());
            $this->assertSame(0, Document::query()->count());
        }
    }
}
