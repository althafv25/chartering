<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A business-rule violation (e.g. "voyage already finalized").
 * Rendered as HTTP 409 with a machine-readable error_code.
 */
class BusinessRuleException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors  optional field-level details
     */
    public function __construct(
        string $message,
        public readonly string $errorCode = 'business_rule_violation',
        public readonly array $errors = [],
        public readonly int $status = 409,
    ) {
        parent::__construct($message);
    }
}
