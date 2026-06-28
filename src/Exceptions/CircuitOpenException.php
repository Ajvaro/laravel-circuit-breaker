<?php

declare(strict_types=1);

namespace Ajvaro\CircuitBreaker\Exceptions;

use RuntimeException;

class CircuitOpenException extends RuntimeException
{
    public function __construct(public readonly string $circuit)
    {
        parent::__construct("Circuit \"{$circuit}\" is open; request rejected.");
    }
}
