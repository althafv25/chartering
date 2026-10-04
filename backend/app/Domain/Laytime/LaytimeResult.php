<?php

namespace App\Domain\Laytime;

class LaytimeResult
{
    /**
     * @param  array<string, mixed>  $trace
     * @param  array<string>  $issues
     */
    public function __construct(
        public readonly ?string $allowedHours,
        public readonly ?string $usedHours,
        public readonly ?string $differenceHours,
        public readonly ?string $demurrageAmount,
        public readonly ?string $despatchAmount,
        public readonly string $calculationVersion,
        public readonly array $trace,
        public readonly array $issues,
    ) {}

    public function isComplete(): bool
    {
        return $this->issues === [] && $this->allowedHours !== null && $this->usedHours !== null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'allowed_hours' => $this->allowedHours,
            'used_hours' => $this->usedHours,
            'difference_hours' => $this->differenceHours,
            'demurrage_amount' => $this->demurrageAmount,
            'despatch_amount' => $this->despatchAmount,
            'calculation_version' => $this->calculationVersion,
            'is_complete' => $this->isComplete(),
            'issues' => $this->issues,
        ];
    }
}
