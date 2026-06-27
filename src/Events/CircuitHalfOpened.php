<?php

declare(strict_types=1);

namespace Nikola\CircuitBreaker\Events;

class CircuitHalfOpened
{
    public function __construct(public readonly string $circuit)
    {
    }
}
