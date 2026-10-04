<?php

namespace App\DTO\Chartering;

use App\Models\OfferRevision;
use Illuminate\Support\Arr;

/** Commercial terms of one offer revision (only keys present are applied). */
final class OfferTermsData
{
    /** @param array<string, mixed> $terms */
    private function __construct(public readonly array $terms) {}

    /** @param array<string, mixed> $validated */
    public static function fromArray(array $validated): self
    {
        $terms = Arr::only($validated, OfferRevision::COMMERCIAL_FIELDS);
        if (isset($terms['currency'])) {
            $terms['currency'] = strtoupper($terms['currency']);
        }
        if (isset($terms['commissions'])) {
            $c = $terms['commissions'];
            $terms['commissions'] = [
                'address_pct' => (string) ($c['address_pct'] ?? '0'),
                'brokerage_pct' => (string) ($c['brokerage_pct'] ?? '0'),
                'other_pct' => (string) ($c['other_pct'] ?? '0'),
                'broker_company_id' => isset($c['broker_company_id']) ? (int) $c['broker_company_id'] : null,
            ];
        }

        return new self($terms);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->terms);
    }
}
