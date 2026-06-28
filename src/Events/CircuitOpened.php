<?php

declare(strict_types=1);

namespace Ajvaro\CircuitBreaker\Events;

class CircuitOpened
{
    public function __construct(public readonly string $circuit)
    {
    }
}
