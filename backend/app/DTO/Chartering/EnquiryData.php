<?php

namespace App\DTO\Chartering;

use Illuminate\Support\Arr;

/** Validated enquiry header data (ports and shortlist are handled separately). */
final class EnquiryData
{
    public const FIELDS = [
        'received_at', 'source', 'business_type', 'charterer_company_id', 'broker_company_id', 'cargo_type_id', 'cargo_description',
        'quantity', 'quantity_unit', 'quantity_tolerance_pct', 'offshore_location_id', 'laycan_from', 'laycan_to', 'period_days',
        'rate_idea', 'rate_basis', 'currency', 'commission_terms', 'terms', 'remarks', 'assigned_to',
    ];

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>|null  $ports  null = unchanged
     */
    private function __construct(public readonly array $attributes, public readonly ?array $ports, public readonly ?int $lockVersion) {}

    /** @param array<string, mixed> $validated */
    public static function fromArray(array $validated): self
    {
        $attrs = Arr::only($validated, self::FIELDS);
        if (isset($attrs['currency'])) {
            $attrs['currency'] = strtoupper($attrs['currency']);
        }

        // validated() fills `ports.*.port_id` rows before `ports.*.offshore_location_id` rows, so a port → location → port
        // itinerary arrives as port, port, location. The array keys are the original row positions: restore that order.
        $ports = $validated['ports'] ?? null;
        if (is_array($ports)) {
            ksort($ports);
        }

        return new self($attrs, array_key_exists('ports', $validated) ? array_values($ports ?? []) : null,
            isset($validated['lock_version']) ? (int) $validated['lock_version'] : null);
    }
}
