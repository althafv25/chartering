<?php

namespace Tests\Unit\Services;

use App\Services\SequenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SequenceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_sequential_padded_numbers_per_key(): void
    {
        $seq = app(SequenceService::class);

        $this->assertSame('INV-2026-00001', $seq->next('invoice:2026', 'INV-2026-'));
        $this->assertSame('INV-2026-00002', $seq->next('invoice:2026', 'INV-2026-'));
        $this->assertSame('FX-001', $seq->next('fixture', 'FX-', 3));
    }

    public function test_rolled_back_transaction_does_not_consume_number(): void
    {
        $seq = app(SequenceService::class);
        $seq->next('k', 'K-');

        try {
            DB::transaction(function () use ($seq) {
                $seq->next('k', 'K-');
                throw new \RuntimeException('business failure');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame('K-00002', $seq->next('k', 'K-'));
    }
}
