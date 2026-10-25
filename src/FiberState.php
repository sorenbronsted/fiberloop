<?php

namespace bronsted;

use Fiber;

/**
 * Bookkeeping for a fiber owned by the loop.
 *
 * @internal
 */
final class FiberState
{
    public bool $queued = false;
    public bool $cancelRequested = false;
    public bool $terminated = false;
    public ?WatcherState $watcher = null;
    public ?WatcherState $pendingWatcher = null;
    public ?TimerEntry $timer = null;

    public function __construct(public readonly Fiber $fiber)
    {
    }
}
