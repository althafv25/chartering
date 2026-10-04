<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * An external provider (AIS, distance, FX, Crew Management API) failed.
 * Rendered as HTTP 502; the provider message is logged, not shown to users.
 */
class IntegrationException extends RuntimeException
{
    public function __construct(
        public readonly string $provider,
        string $message = 'An external service is currently unavailable.',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
