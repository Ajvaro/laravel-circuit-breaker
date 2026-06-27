<?php

declare(strict_types=1);

namespace Nikola\CircuitBreaker\Events;

class CircuitClosed
{
    public function __construct(public readonly string $circuit)
    {
    }
}
