<?php

namespace Tests\Unit\Services\Ais;

use App\Services\Ais\TrackSimplifier;
use PHPUnit\Framework\TestCase;

class TrackSimplifierTest extends TestCase
{
    public function test_a_straight_line_collapses_to_its_end_points(): void
    {
        $points = array_map(fn (int $i) => ['lat' => 10 + $i * 0.01, 'lon' => 50 + $i * 0.02], range(0, 499));

        // Under the cap nothing is dropped; over it, collinear points collapse to the end points.
        $this->assertCount(500, TrackSimplifier::simplifyIndexes($points, 2000));
        $this->assertSame([0, 499], TrackSimplifier::simplifyIndexes($points, 100));
    }

    public function test_short_tracks_are_returned_unchanged(): void
    {
        $points = array_map(fn (int $i) => ['lat' => $i * 0.5, 'lon' => ($i % 2) * 0.5], range(0, 9));

        $this->assertSame(range(0, 9), TrackSimplifier::simplifyIndexes($points, 2000));
        $this->assertSame([], TrackSimplifier::simplifyIndexes([], 2000));
    }

    public function test_noisy_track_is_capped_and_keeps_both_ends_in_order(): void
    {
        mt_srand(42);
        $points = array_map(fn (int $i) => ['lat' => sin($i / 40) * 5 + mt_rand(0, 100) / 1000, 'lon' => $i * 0.01 + mt_rand(0, 100) / 1000], range(0, 4999));

        $keep = TrackSimplifier::simplifyIndexes($points, 300);

        $this->assertLessThanOrEqual(300, count($keep));
        $this->assertSame(0, $keep[0]);
        $this->assertSame(4999, end($keep));
        $sorted = $keep;
        sort($sorted);
        $this->assertSame($sorted, $keep);
    }
}
