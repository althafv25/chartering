<?php

namespace App\Domain\Cii;

class CiiResult
{
    /**
     * @param  array<string, mixed>  $trace
     * @param  array<string>  $issues
     */
    public function __construct(
        public readonly ?string $attainedCii,
        public readonly ?string $requiredCii,
        public readonly ?string $rating,
        public readonly ?string $ratio,
        public readonly string $calculationVersion,
        public readonly array $trace,
        public readonly array $issues,
    ) {}

    public function isComplete(): bool
    {
        return $this->issues === [] && $this->attainedCii !== null && $this->rating !== null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'attained_cii' => $this->attainedCii,
            'required_cii' => $this->requiredCii,
            'rating' => $this->rating,
            'ratio' => $this->ratio,
            'calculation_version' => $this->calculationVersion,
            'is_complete' => $this->isComplete(),
            'issues' => $this->issues,
        ];
    }
}
