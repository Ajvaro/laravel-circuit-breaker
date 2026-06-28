<?php

declare(strict_types=1);

namespace Ajvaro\CircuitBreaker;

enum State: string
{
    case Closed = 'closed';
    case Open = 'open';
    case HalfOpen = 'half_open';

    public function isClosed(): bool
    {
        return $this === self::Closed;
    }

    public function isOpen(): bool
    {
        return $this === self::Open;
    }

    public function isHalfOpen(): bool
    {
        return $this === self::HalfOpen;
    }
}
