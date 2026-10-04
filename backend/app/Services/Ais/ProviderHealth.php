<?php

namespace App\Services\Ais;

final readonly class ProviderHealth
{
    public function __construct(public bool $ok, public string $message, public ?string $lastError = null) {}

    /** @return array{ok: bool, message: string, last_error: string|null} */
    public function toArray(): array
    {
        return ['ok' => $this->ok, 'message' => $this->message, 'last_error' => $this->lastError];
    }
}
