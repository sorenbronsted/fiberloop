<?php

namespace bronsted;

use Closure;
use Fiber;
use LogicException;
use SplPriorityQueue;
use SplQueue;
use Throwable;
use function floor;
use function get_resource_id;
use function is_resource;
use function max;
use function microtime;
use function spl_object_id;
use function time_nanosleep;

/**
 * This is the heart of the fiberloop, where you can schedule coroutines for different work.
 */
class FiberLoop
{
    /**
     * @var SplQueue<FiberState> fibers that are ready to run
     */
    private SplQueue $ready;

    /**
     * @var SplPriorityQueue<TimerEntry> timer heap, priority is the negative deadline
     */
    private SplPriorityQueue $timers;

    /**
     * @var array<int, WatcherState> read watchers keyed by resource id
     */
    private array $readers = [];

    /**
     * @var array<int, WatcherState> write watchers keyed by resource id
     */
    private array $writers = [];

    /**
     * @var array<int, FiberState> all live fibers keyed by object id
     */
    private array $fibers = [];

    private bool $stopped = false;
    private int $nextWatcherId = 1;

    /**
     * @var FiberLoop|null singleton if used
     */
    private static ?FiberLoop $instance = null;

    /**
     * @var FiberLoop|null the loop currently running
     */
    private static ?FiberLoop $current = null;

    public function __construct()
    {
        $this->ready = new SplQueue();
        $this->timers = new SplPriorityQueue();
        $this->timers->setExtractFlags(SplPriorityQueue::EXTR_DATA);
    }

    /**
     * Use the FiberLoop as a singleton
     */
    public static function instance(): FiberLoop
    {
        return self::$instance ??= new FiberLoop();
    }

    /**
     * The loop currently running, or the singleton if no loop is running.
     */
    public static function current(): FiberLoop
    {
        return self::$current ?? self::instance();
    }

    /**
     * Defer the call of closure in the future
     */
    public function defer(Closure $closure): void
    {
        $this->spawn(\bronsted\defer(...), $closure);
    }

    /**
     * Delay the call of the closure to seconds in the future
     */
    public function delay(float $seconds, Closure $closure): void
    {
        $this->spawn(\bronsted\delay(...), $seconds, $closure);
    }

    /**
     * Repeat the call of the closure every interval in the future.
     * The closure can change the interval by returning a new interval. If the interval is 0 the repeating
     * call stop.
     */
    public function repeat(float $interval, Closure $closure): void
    {
        $this->spawn(\bronsted\repeat(...), $interval, $closure);
    }

    /**
     * Call the closure every time the resource has data to be read.
     *
     * The watcher is removed automatically when the resource is closed with fclose().
     */
    public function onReadable(mixed $resource, Closure $closure): Watcher
    {
        $state = new WatcherState($this->nextWatcherId++, $resource, 'read', false);
        $watcher = new Watcher($this, $state);

        try {
            $fiber = $this->spawn(\bronsted\readStream(...), $resource, $closure, $watcher);
        } catch (Throwable $e) {
            $this->closeWatcher($state);
            throw $e;
        }

        if ($fiber->terminated) {
            $this->closeWatcher($state);
        }

        return $watcher;
    }

    /**
     * Call the closure every time the resource is ready to be written.
     *
     * The watcher is removed automatically when the resource is closed with fclose().
     */
    public function onWriteable(mixed $resource, Closure $closure): Watcher
    {
        $state = new WatcherState($this->nextWatcherId++, $resource, 'write', false);
        $watcher = new Watcher($this, $state);

        try {
            $fiber = $this->spawn(\bronsted\writeStream(...), $resource, $closure, $watcher);
        } catch (Throwable $e) {
            $this->closeWatcher($state);
            throw $e;
        }

        if ($fiber->terminated) {
            $this->closeWatcher($state);
        }

        return $watcher;
    }

    /**
     * Run the loop until there are no more referenced work.
     */
    public function run(): void
    {
        $previous = self::$current;
        self::$current = $this;
        $this->stopped = false;

        try {
            while (true) {
                $this->processTimers();
                $this->processReady();

                if ($this->stopped) {
                    $this->cancelAllPending();
                    $guard = 0;
                    while (!$this->ready->isEmpty() && $guard++ < 10_000) {
                        $this->processReady();
                    }
                    break;
                }

                $this->pruneResourceMaps();
                if (!$this->ready->isEmpty()) {
                    continue;
                }

                if (!$this->hasReferencedWork()) {
                    break;
                }

                [$read, $write] = $this->buildSelectArrays();

                if ($read === [] && $write === []) {
                    $deadline = $this->nextTimerDeadline();
                    if ($deadline === null) {
                        // @codeCoverageIgnoreStart
                        // Defensive: hasReferencedWork() guarantees work here.
                        break;
                        // @codeCoverageIgnoreEnd
                    }
                    $this->sleepUntil($deadline);
                    continue;
                }

                $deadline = $this->nextTimerDeadline();
                $timeout = $deadline === null ? null : max(0.0, $deadline - microtime(true));
                [$seconds, $microseconds] = $this->splitTimeout($timeout);

                $except = null;
                $result = @stream_select($read, $write, $except, $seconds, $microseconds);
                if ($result === false) {
                    // @codeCoverageIgnoreStart
                    // Only EINTR or an invalid descriptor; retry on the next iteration.
                    continue;
                    // @codeCoverageIgnoreEnd
                }

                if ($result > 0) {
                    $this->markReadable($read);
                    $this->markWriteable($write);
                }
            }
        } finally {
            self::$current = $previous;
        }
    }

    /**
     * Stop the loop and cancel all pending work.
     */
    public function stop(): void
    {
        $this->stopped = true;
        $this->cancelAllPending();
    }

    /**
     * Cancel a watcher.
     *
     * @internal
     */
    public function cancelWatcher(WatcherState $watcher): void
    {
        if ($watcher->closed || $watcher->cancelled) {
            return;
        }

        $state = $watcher->fiber;
        if ($state === null) {
            $watcher->cancelled = true;
            return;
        }

        // fclose() is normal termination, not cancellation. If the resource is
        // already gone, let the fiber resume normally instead of throwing.
        if (!is_resource($watcher->resource)) {
            $this->unregisterWatcher($watcher);
            $watcher->closed = true;
            if ($state->fiber->isSuspended()) {
                $this->enqueueReady($state);
            }
            return;
        }

        $wasActive = $watcher->active;
        $this->unregisterWatcher($watcher);
        $watcher->cancelled = true;

        if ($state->terminated || $state->fiber->isTerminated()) {
            return;
        }

        if ($wasActive || $state->pendingWatcher === $watcher || Fiber::getCurrent() === $state->fiber) {
            $state->cancelRequested = true;
            if ($state->fiber->isSuspended()) {
                $this->enqueueReady($state);
            }
        }
    }

    /**
     * Wake a fiber parked by Suspend or waiting on I/O.
     *
     * @internal
     */
    public function wake(Fiber $fiber): void
    {
        $state = $this->fibers[spl_object_id($fiber)] ?? null;
        if ($state === null || $state->terminated || $fiber->isTerminated() || !$fiber->isSuspended()) {
            return;
        }

        if ($state->watcher !== null && $state->watcher->active) {
            $this->unregisterWatcher($state->watcher);
        }
        if ($state->timer !== null) {
            $state->timer->cancelled = true;
            $state->timer = null;
        }

        $state->pendingWatcher = null;
        $this->enqueueReady($state);
    }

    /**
     * Wrap the closure in a fiber and start it.
     */
    private function spawn(Closure $closure, mixed ...$args): FiberState
    {
        $fiber = new Fiber($closure);
        $state = new FiberState($fiber);
        $this->fibers[spl_object_id($fiber)] = $state;

        try {
            $wait = $fiber->start(...$args);
            if ($fiber->isTerminated()) {
                $this->terminateFiber($state);
                return $state;
            }
            $this->handleSuspension($state, $wait);
        } catch (Throwable $e) {
            $this->terminateFiber($state);
            throw $e;
        }

        return $state;
    }

    /**
     * Handle the value a fiber passed to Fiber::suspend().
     */
    private function handleSuspension(FiberState $state, mixed $value): void
    {
        if ($state->terminated) {
            return;
        }
        if ($state->fiber->isTerminated()) {
            $this->terminateFiber($state);
            return;
        }
        if ($state->cancelRequested) {
            $this->enqueueReady($state);
            return;
        }
        if ($this->stopped) {
            $state->cancelRequested = true;
            $this->enqueueReady($state);
            return;
        }

        $wait = $value instanceof Wait ? $value : Wait::ready();

        switch ($wait->kind) {
            case WaitKind::Ready:
                $this->enqueueReady($state);
                break;

            case WaitKind::Timer:
                $this->scheduleTimer($state, (float) $wait->deadline);
                break;

            case WaitKind::Park:
                if ($wait->deadline !== null) {
                    $this->scheduleTimer($state, $wait->deadline);
                }
                break;

            case WaitKind::Read:
            case WaitKind::Write:
                $this->registerWatcher($state, $wait);
                break;
        }
    }

    /**
     * @param array<int, mixed> $ready
     */
    private function markReadable(array $ready): void
    {
        foreach ($ready as $id => $resource) {
            $watcher = $this->readers[$id] ?? null;
            if ($watcher === null || $watcher->resource !== $resource) {
                continue;
            }

            unset($this->readers[$id]);
            $watcher->active = false;
            $state = $watcher->fiber;

            if (!is_resource($watcher->resource)) {
                $this->closeWatcher($watcher);
            }
            if ($state !== null) {
                $state->pendingWatcher = $watcher;
                $this->enqueueReady($state);
            }
        }
    }

    /**
     * @param array<int, mixed> $ready
     */
    private function markWriteable(array $ready): void
    {
        foreach ($ready as $id => $resource) {
            $watcher = $this->writers[$id] ?? null;
            if ($watcher === null || $watcher->resource !== $resource) {
                continue;
            }

            unset($this->writers[$id]);
            $watcher->active = false;
            $state = $watcher->fiber;

            if (!is_resource($watcher->resource)) {
                $this->closeWatcher($watcher);
            }
            if ($state !== null) {
                $state->pendingWatcher = $watcher;
                $this->enqueueReady($state);
            }
        }
    }

    private function registerWatcher(FiberState $state, Wait $wait): void
    {
        $resource = $wait->resource;
        $direction = $wait->kind === WaitKind::Read ? 'read' : 'write';
        $provided = $wait->watcher;

        if (!is_resource($resource)) {
            if ($provided !== null) {
                $this->closeWatcher($provided->state);
            } elseif ($state->watcher !== null && $state->watcher->transient) {
                $this->closeWatcher($state->watcher);
            }
            $this->enqueueReady($state);
            return;
        }

        if ($provided !== null) {
            $watcher = $provided->state;
            if ($watcher->closed) {
                $this->enqueueReady($state);
                return;
            }
            if ($watcher->cancelled) {
                $state->cancelRequested = true;
                $this->enqueueReady($state);
                return;
            }
            if ($watcher->direction !== $direction || $watcher->resource !== $resource) {
                throw new LogicException('The watcher does not match the stream wait');
            }
            $watcher->resourceId = get_resource_id($resource);
        } else {
            $watcher = $state->watcher;
            if (
                $watcher === null
                || !$watcher->transient
                || $watcher->closed
                || $watcher->direction !== $direction
                || $watcher->resource !== $resource
            ) {
                if ($watcher !== null && $watcher->transient) {
                    $this->closeWatcher($watcher);
                }
                $watcher = new WatcherState(
                    $this->nextWatcherId++,
                    $resource,
                    $direction,
                    true,
                );
                $watcher->resourceId = get_resource_id($resource);
            }
            if ($watcher->cancelled) {
                $state->cancelRequested = true;
                $this->enqueueReady($state);
                return;
            }
        }

        $this->pruneResourceMap($direction);

        $id = $watcher->resourceId;
        $map = $direction === 'read' ? $this->readers : $this->writers;

        if (isset($map[$id])) {
            throw new LogicException(
                "A {$direction} watcher is already registered for this resource"
            );
        }

        $watcher->fiber = $state;
        $watcher->active = true;
        $watcher->closed = false;
        $state->watcher = $watcher;
        $map[$id] = $watcher;

        if ($direction === 'read') {
            $this->readers = $map;
        } else {
            $this->writers = $map;
        }
    }

    private function unregisterWatcher(WatcherState $watcher): void
    {
        if ($watcher->active && $watcher->resourceId !== null) {
            if ($watcher->direction === 'read') {
                if (($this->readers[$watcher->resourceId] ?? null) === $watcher) {
                    unset($this->readers[$watcher->resourceId]);
                }
            } elseif (($this->writers[$watcher->resourceId] ?? null) === $watcher) {
                unset($this->writers[$watcher->resourceId]);
            }
        }
        $watcher->active = false;
    }

    private function closeWatcher(WatcherState $watcher): void
    {
        $this->unregisterWatcher($watcher);
        $watcher->closed = true;
    }

    private function pruneResourceMaps(): void
    {
        $this->pruneResourceMap('read');
        $this->pruneResourceMap('write');
    }

    private function pruneResourceMap(string $direction): void
    {
        $map = $direction === 'read' ? $this->readers : $this->writers;

        foreach ($map as $id => $watcher) {
            if (is_resource($watcher->resource)) {
                continue;
            }

            unset($map[$id]);
            $watcher->active = false;
            $state = $watcher->fiber;
            $this->closeWatcher($watcher);
            if ($state !== null) {
                $this->enqueueReady($state);
            }
        }

        if ($direction === 'read') {
            $this->readers = $map;
        } else {
            $this->writers = $map;
        }
    }

    private function scheduleTimer(FiberState $state, float $deadline): void
    {
        if ($state->timer !== null) {
            $state->timer->cancelled = true;
            $state->timer = null;
        }

        $entry = new TimerEntry($deadline, $state);
        $state->timer = $entry;
        $this->timers->insert($entry, -$deadline);
    }

    private function processTimers(): void
    {
        $now = microtime(true);

        while (!$this->timers->isEmpty()) {
            $entry = $this->timers->top();

            if (
                $entry->cancelled
                || $entry->state->terminated
                || $entry->state->timer !== $entry
            ) {
                $this->timers->extract();
                continue;
            }

            if ($entry->deadline > $now) {
                return;
            }

            $this->timers->extract();
            $entry->state->timer = null;
            $this->enqueueReady($entry->state);
        }
    }

    private function nextTimerDeadline(): ?float
    {
        while (!$this->timers->isEmpty()) {
            $entry = $this->timers->top();

            if (
                $entry->cancelled
                || $entry->state->terminated
                || $entry->state->timer !== $entry
            ) {
                $this->timers->extract();
                continue;
            }

            return $entry->deadline;
        }

        return null;
    }

    private function enqueueReady(FiberState $state): void
    {
        if ($state->terminated || $state->queued) {
            return;
        }

        $state->queued = true;
        $this->ready->enqueue($state);
    }

    private function processReady(): void
    {
        $count = $this->ready->count();

        while ($count-- > 0 && !$this->ready->isEmpty()) {
            $state = $this->ready->dequeue();
            $state->queued = false;
            $state->pendingWatcher = null;

            if ($state->terminated || !$state->fiber->isSuspended()) {
                continue;
            }

            $cancel = $state->cancelRequested;
            $state->cancelRequested = false;

            try {
                $wait = $cancel
                    ? $state->fiber->throw(new CancelledException('Watcher cancelled'))
                    : $state->fiber->resume();

                if ($state->fiber->isTerminated()) {
                    $this->terminateFiber($state);
                } else {
                    $this->handleSuspension($state, $wait);
                }
            } catch (CancelledException) {
                $this->terminateFiber($state);
            } catch (Throwable $e) {
                $this->terminateFiber($state);
                throw $e;
            }
        }
    }

    private function terminateFiber(FiberState $state): void
    {
        if ($state->terminated) {
            return;
        }

        $state->terminated = true;
        unset($this->fibers[spl_object_id($state->fiber)]);

        if ($state->watcher !== null) {
            $this->closeWatcher($state->watcher);
            $state->watcher->fiber = null;
        }

        if ($state->timer !== null) {
            $state->timer->cancelled = true;
            $state->timer = null;
        }

        $state->queued = false;
        $state->cancelRequested = false;
        $state->pendingWatcher = null;
    }

    private function requestCancel(FiberState $state): void
    {
        if ($state->terminated || $state->fiber->isTerminated()) {
            return;
        }

        if ($state->watcher !== null && $state->watcher->active) {
            $this->unregisterWatcher($state->watcher);
            $state->watcher->cancelled = true;
        }

        if ($state->timer !== null) {
            $state->timer->cancelled = true;
            $state->timer = null;
        }

        $state->cancelRequested = true;
        $state->pendingWatcher = null;

        if ($state->fiber->isSuspended()) {
            $this->enqueueReady($state);
        }
    }

    private function cancelAllPending(): void
    {
        foreach ($this->fibers as $state) {
            if ($state->terminated || !$state->fiber->isSuspended()) {
                continue;
            }
            $this->requestCancel($state);
        }
    }

    private function hasReferencedWork(): bool
    {
        if (!$this->ready->isEmpty()) {
            return true;
        }

        if ($this->nextTimerDeadline() !== null) {
            return true;
        }

        foreach ($this->readers as $watcher) {
            if ($watcher->referenced) {
                return true;
            }
        }
        foreach ($this->writers as $watcher) {
            if ($watcher->referenced) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: array<int, mixed>, 1: array<int, mixed>}
     */
    private function buildSelectArrays(): array
    {
        $read = [];
        $write = [];

        foreach ($this->readers as $id => $watcher) {
            if (is_resource($watcher->resource)) {
                $read[$id] = $watcher->resource;
            }
        }
        foreach ($this->writers as $id => $watcher) {
            if (is_resource($watcher->resource)) {
                $write[$id] = $watcher->resource;
            }
        }

        return [$read, $write];
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private function splitTimeout(?float $timeout): array
    {
        if ($timeout === null) {
            return [null, null];
        }

        $timeout = max(0.0, $timeout);
        $seconds = (int) floor($timeout);
        $microseconds = (int) (($timeout - $seconds) * 1_000_000);

        return [$seconds, $microseconds];
    }

    private function sleepUntil(float $deadline): void
    {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            return;
        }

        $seconds = (int) floor($remaining);
        $nanoseconds = (int) (($remaining - $seconds) * 1_000_000_000);

        @time_nanosleep($seconds, $nanoseconds);
    }
}
