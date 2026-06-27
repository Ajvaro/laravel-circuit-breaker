<?php

declare(strict_types=1);

namespace Nikola\CircuitBreaker\Exceptions;

use RuntimeException;

class CircuitOpenException extends RuntimeException
{
    public function __construct(public readonly string $circuit)
    {
        parent::__construct("Circuit \"{$circuit}\" is open; request rejected.");
    }
}
