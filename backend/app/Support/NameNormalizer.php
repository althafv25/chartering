<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Normalises organisation / port names for duplicate detection:
 * "Gulf Marine Services L.L.C." → "gulf marine services".
 */
final class NameNormalizer
{
    private const LEGAL_SUFFIXES = [
        'llc', 'l l c', 'ltd', 'limited', 'inc', 'incorporated', 'co', 'company', 'corp', 'corporation',
        'plc', 'fze', 'fzco', 'fzc', 'fzllc', 'dmcc', 'pte', 'pvt', 'gmbh', 'bv', 'nv', 'sa', 'srl', 'as', 'ab', 'llp', 'wll', 'spc',
    ];

    public static function normalize(string $name): string
    {
        $s = Str::lower(Str::ascii($name));
        $s = str_replace(['&', '+'], ' and ', $s);
        $s = preg_replace('/[^a-z0-9 ]+/', ' ', $s) ?? '';
        $s = trim(preg_replace('/\s+/', ' ', $s) ?? '');

        // Strip trailing legal-form tokens repeatedly ("... co llc").
        do {
            $before = $s;
            foreach (self::LEGAL_SUFFIXES as $suffix) {
                if ($s !== $suffix && str_ends_with($s, ' '.$suffix)) {
                    $s = trim(substr($s, 0, -strlen($suffix) - 1));
                }
            }
        } while ($s !== $before);

        return mb_substr($s, 0, 200);
    }
}
