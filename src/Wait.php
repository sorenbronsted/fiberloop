<?php

namespace bronsted;

use Closure;

/**
 * Describes when the loop should resume a fiber.
 * @internal
 */
final class Wait
{
    public function __construct(
        public readonly ?float $deadline = null,
        public readonly mixed $read = null,
        public readonly mixed $write = null,
        public readonly ?Closure $condition = null
    ) {
    }

    public function isReady(float $now): bool
    {
        return ($this->deadline !== null && $this->deadline <= $now)
            || ($this->condition !== null && ($this->condition)())
            || ($this->read !== null && !isStreamValid($this->read))
            || ($this->write !== null && !isStreamValid($this->write, false));
    }
}
