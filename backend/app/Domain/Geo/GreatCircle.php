<?php

namespace App\Domain\Geo;

/**
 * Great-circle (haversine) distance in nautical miles.
 *
 * This is a straight-line ESTIMATE over the sphere: it ignores land, routing,
 * canals and ECA zones and always under-states the sailing distance. It is
 * only used as a clearly-labelled fallback when no stored/provider distance
 * exists. Trigonometry needs floats; the result is rounded to 2 dp and is
 * never used as money.
 */
final class GreatCircle
{
    /** Mean Earth radius in nautical miles (6371.0088 km / 1.852). */
    public const EARTH_RADIUS_NM = 3440.0695;

    public static function distanceNm(float $lat1, float $lon1, float $lat2, float $lon2): string
    {
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dPhi = deg2rad($lat2 - $lat1);
        $dLambda = deg2rad($lon2 - $lon1);

        $a = sin($dPhi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dLambda / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return number_format(self::EARTH_RADIUS_NM * $c, 2, '.', '');
    }
}
