<?php

namespace App\Services\Ais;

use SplPriorityQueue;

/**
 * Track simplification for map display: best-first Douglas–Peucker. The segment whose farthest point is most
 * significant is split first, until the cap is reached, so work is bounded by the cap rather than by the track
 * length and the result is the best N points by significance. Points on a straight line have no significance and are
 * dropped. Works on an equirectangular projection in degrees (fine for drawing; distances are computed separately
 * from the full-resolution points).
 */
final class TrackSimplifier
{
    /** Distances below this (degrees, ~0.1 mm) count as "on the line". */
    private const NEGLIGIBLE = 1e-9;

    /**
     * @param  list<array{lat: float, lon: float}>  $points
     * @return list<int> indexes of the points to keep, ascending; always includes the first and last point
     */
    public static function simplifyIndexes(array $points, int $maxPoints): array
    {
        $n = count($points);
        if ($n <= 2 || $n <= $maxPoints) {
            return $n === 0 ? [] : range(0, $n - 1);
        }

        // Flat coordinate arrays: the inner loop below runs hundreds of thousands of times.
        $lat = array_column($points, 'lat');
        $lon = array_column($points, 'lon');
        $keep = [0 => true, $n - 1 => true];
        $queue = new SplPriorityQueue;
        $queue->setExtractFlags(SplPriorityQueue::EXTR_DATA);
        $enqueue = function (int $start, int $end) use ($lat, $lon, $queue): void {
            [$distance, $index] = self::farthest($lat, $lon, $start, $end);
            if ($index !== -1 && $distance > self::NEGLIGIBLE) {
                $queue->insert([$start, $end, $index], $distance);
            }
        };

        $enqueue(0, $n - 1);
        while (! $queue->isEmpty() && count($keep) < $maxPoints) {
            [$start, $end, $index] = $queue->extract();
            $keep[$index] = true;
            $enqueue($start, $index);
            $enqueue($index, $end);
        }
        $indexes = array_keys($keep);
        sort($indexes);

        return $indexes;
    }

    /**
     * @param  list<float>  $lat
     * @param  list<float>  $lon
     * @return array{0: float, 1: int} [distance, index] of the interior point farthest from the chord; index -1 when there is none
     */
    private static function farthest(array $lat, array $lon, int $start, int $end): array
    {
        $ax = $lon[$start];
        $ay = $lat[$start];
        $dx = $lon[$end] - $ax;
        $dy = $lat[$end] - $ay;
        $len = $dx * $dx + $dy * $dy;
        $maxDist = 0.0;
        $index = -1;
        for ($i = $start + 1; $i < $end; $i++) {
            $px = $lon[$i] - $ax;
            $py = $lat[$i] - $ay;
            if ($len == 0.0) {
                $d = sqrt($px * $px + $py * $py);
            } else {
                $t = ($px * $dx + $py * $dy) / $len;
                $t = $t < 0.0 ? 0.0 : ($t > 1.0 ? 1.0 : $t);
                $ex = $px - $t * $dx;
                $ey = $py - $t * $dy;
                $d = sqrt($ex * $ex + $ey * $ey);
            }
            if ($d > $maxDist) {
                $maxDist = $d;
                $index = $i;
            }
        }

        return [$maxDist, $index];
    }
}
