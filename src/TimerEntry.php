<?php

namespace bronsted;

/**
 * Entry in the loop's timer heap.
 *
 * @internal
 */
final class TimerEntry
{
    public bool $cancelled = false;

    public function __construct(
        public readonly float $deadline,
        public readonly FiberState $state,
    ) {
    }
}
