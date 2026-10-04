<?php

namespace App\Domain\Estimation;

use App\Support\Decimal;

/** Ordered, human-readable calculation steps with exact (unrounded) values. */
final class Trace
{
    /** @var list<array{ref:string,label:string,formula:string,value:string|null}> */
    private array $steps = [];

    /** @var list<string> */
    private array $warnings = [];

    public function __construct(private readonly bool $enabled = true) {}

    public function add(string $ref, string $label, string $formula, ?string $value): void
    {
        if ($this->enabled) {
            $this->steps[] = ['ref' => $ref, 'label' => $label, 'formula' => $formula, 'value' => $value === null ? null : Decimal::trim($value)];
        }
    }

    public function warn(string $message): void
    {
        if ($this->enabled && ! in_array($message, $this->warnings, true)) {
            $this->warnings[] = $message;
        }
    }

    /** @return list<array{ref:string,label:string,formula:string,value:string|null}> */
    public function steps(): array
    {
        return $this->steps;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public static function n(string $v): string
    {
        return Decimal::trim($v);
    }
}
