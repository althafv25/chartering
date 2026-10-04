<?php

namespace App\Support;

use App\Exceptions\BusinessRuleException;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

/**
 * G-06: operational times are entered in port/location-local time and stored in UTC.
 * Input "YYYY-MM-DDTHH:mm[:ss]" without offset is interpreted in $tz; input with an
 * explicit offset/Z is honoured as-is.
 */
final class LocalTime
{
    public static function toUtc(?string $value, string $tz, string $field = 'time'): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        try {
            $hasOffset = (bool) preg_match('/(Z|[+-]\d{2}:?\d{2})$/', trim($value));

            return ($hasOffset ? CarbonImmutable::parse($value) : CarbonImmutable::parse($value, $tz))->utc();
        } catch (Throwable) {
            throw new BusinessRuleException("Invalid date/time for {$field}.", 'invalid_datetime', [$field => ['Invalid date/time.']], 422);
        }
    }

    /** Naive local representation for form fields, e.g. "2026-10-05T08:00". */
    public static function toLocal(?DateTimeInterface $utc, string $tz): ?string
    {
        return $utc ? CarbonImmutable::instance($utc)->setTimezone($tz)->format('Y-m-d\TH:i') : null;
    }

    /** Exact hours between two instants (4 dp, bcmath). */
    public static function hoursBetween(DateTimeInterface $from, DateTimeInterface $to): string
    {
        $seconds = $to->getTimestamp() - $from->getTimestamp();

        return Decimal::round(Decimal::div((string) $seconds, '3600'), 4);
    }
}
