<?php

namespace bronsted;

/**
 * Internal state behind a Watcher handle.
 *
 * The state survives the callback, so a watcher can be cancelled from
 * inside its own callback and take effect at the next suspension.
 *
 * @internal
 */
final class WatcherState
{
    public ?int $resourceId = null;
    public ?FiberState $fiber = null;
    public bool $referenced = true;
    public bool $active = false;
    public bool $cancelled = false;
    public bool $closed = false;

    public function __construct(
        public readonly int $id,
        public readonly mixed $resource,
        public readonly string $direction,
        public readonly bool $transient,
    ) {
    }
}
