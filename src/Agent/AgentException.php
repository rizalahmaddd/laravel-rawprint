<?php

declare(strict_types=1);

namespace RawPrint\Laravel\Agent;

use RuntimeException;
use Throwable;

class AgentException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'agent_error',
        public readonly int $status = 0,
        public readonly array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
