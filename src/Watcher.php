<?php

namespace bronsted;

/**
 * Handle for a registered read or write watcher.
 *
 * Cancelling a watcher throws a CancelledException into the waiting fiber.
 * Ref/unref controls whether the watcher keeps the loop alive.
 */
final class Watcher
{
    /**
     * @internal
     */
    public function __construct(
        private readonly FiberLoop $loop,
        /** @internal */
        public readonly WatcherState $state,
    ) {
    }

    /**
     * Cancel the watcher. The waiting fiber receives a CancelledException
     * at its suspension point, so finally blocks run.
     */
    public function cancel(): void
    {
        $this->loop->cancelWatcher($this->state);
    }

    /**
     * Keep the loop alive while this watcher exists.
     */
    public function ref(): void
    {
        if (!$this->state->closed) {
            $this->state->referenced = true;
        }
    }

    /**
     * Stop this watcher from keeping the loop alive.
     *
     * The watcher is still active and will run while the loop is alive for
     * other reasons.
     */
    public function unref(): void
    {
        if (!$this->state->closed) {
            $this->state->referenced = false;
        }
    }

    public function isReferenced(): bool
    {
        return !$this->state->closed && $this->state->referenced;
    }

    public function isActive(): bool
    {
        return !$this->state->closed && $this->state->active;
    }
}
