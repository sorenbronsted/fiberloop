<?php

namespace bronsted;

use Closure;
use Fiber;
use SplQueue;
use function microtime;
use function usleep;


/**
 * This is the heart of the fiberloop, where you can schedule coroutines for different work.
 */
class FiberLoop
{
    /**
     * @var SplQueue the holding current fibers
     */
    private SplQueue $queue;

    /**
     * @var array<int, array{Fiber, Wait}> fibers waiting for I/O or a deadline
     */
    private array $waiting = [];

    /**
     * @var FiberLoop|null singleton if used
     */
    private static ?FiberLoop $instance = null;

    public function __construct()
    {
        $this->queue  = new SplQueue();
    }

    /**
     * Use the FiberLoop as a singleton
     * @return FiberLoop
     */
    public static function instance(): FiberLoop
    {
        if (self::$instance == null) {
            self::$instance = new FiberLoop();
        }
        return self::$instance;
    }

    /**
     * Defer the call of closure in the future
     * @param Closure $closure
     * @return void
     */
    public function defer(Closure $closure)
    {
        $this->enqueue(defer(...), $closure);
    }

    /**
     * Delay the call of the closure to seconds in the future
     * @param float $seconds
     * @param Closure $closure
     * @return void
     */
    public function delay(float $seconds, Closure $closure)
    {
        $this->enqueue(delay(...), $seconds, $closure);
    }

    /**
     * Repeat the call of the closure every interval in the future.
     * The closure can change the interval by returning a new interval. If the interval is 0 the repeating
     * call stop.
     * @param float $interval
     * @param Closure $closure
     * @return void
     */
    public function repeat(float $interval, Closure $closure)
    {
        $this->enqueue(repeat(...), $interval, $closure);
    }

    /**
     * Call the closure as long as the resource is valid and the resource has data to be read.
     * @param mixed $resource
     * @param Closure $closure
     * @param int $timeout maximum polling interval in microseconds; 0 waits for readiness
     * @return void
     */
    public function onReadable(mixed $resource, Closure $closure, int $timeout = 0)
    {
        $this->enqueue(readStream(...), $resource, $closure, $timeout);
    }

    /**
     * Call the closure once when the resource has data to be read.
     * @param mixed $resource
     * @param Closure $closure
     * @param int $timeout maximum polling interval in microseconds; 0 waits for readiness
     * @return void
     */
    public function onReadableOnce(mixed $resource, Closure $closure, int $timeout = 0)
    {
        $this->enqueue(readStreamOnce(...), $resource, $closure, $timeout);
    }

    /**
     * Call the closure as long as the resource is valid and the resource is ready to be written.
     * @param mixed $resource
     * @param Closure $closure
     * @return void
     */
    public function onWriteable(mixed $resource, Closure $closure)
    {
        $this->enqueue(writeStream(...), $resource, $closure);
    }

    /**
     * Call the closure once when the resource is ready to written.
     * @param mixed $resource
     * @param Closure $closure
     * @return void
     */
    public function onWriteableReady(mixed $resource, Closure $closure)
    {
        $this->enqueue(writeStreamOnce(...), $resource, $closure);
    }

    /**
     * Run the loop until there are no more runnable or waiting fibers
     * @return void
     */
    public function run()
    {
        while (!$this->queue->isEmpty() || $this->waiting) {
            $this->waitForWork($this->queue->isEmpty());
            $count = $this->queue->count();
            for ($i = 0; $i < $count && !$this->queue->isEmpty(); $i++) {
                $fiber = $this->queue->dequeue();
                if ($fiber->isSuspended()) {
                    $this->schedule($fiber, $fiber->resume());
                }
            }
        }
    }

    /**
     * Poll all streams together, blocking only when there is no runnable work.
     */
    private function waitForWork(bool $block): void
    {
        $read = [];
        $write = [];
        $deadline = null;
        $now = microtime(true);

        foreach ($this->waiting as $id => [$fiber, $wait]) {
            if ($wait->isReady($now)) {
                unset($this->waiting[$id]);
                $this->queue->enqueue($fiber);
                $block = false;
                continue;
            }
            if ($wait->read !== null) {
                $read[(int) $wait->read] = $wait->read;
            }
            if ($wait->write !== null) {
                $write[(int) $wait->write] = $wait->write;
            }
            if ($wait->deadline !== null) {
                $deadline = $deadline === null ? $wait->deadline : min($deadline, $wait->deadline);
            }
        }

        if (!$this->waiting) {
            return;
        }
        $timeout = $block ? ($deadline === null ? null : max(0, $deadline - microtime(true))) : 0;
        $microseconds = $timeout === null ? 0 : (int) ceil($timeout * 1000000);

        if ($read || $write) {
            $except = null;
            $seconds = $timeout === null ? null : intdiv($microseconds, 1000000);
            if (stream_select($read, $write, $except, $seconds, $microseconds % 1000000) === false) {
                return;
            }
        }
        elseif ($microseconds > 0) {
            usleep($microseconds);
        }

        $now = microtime(true);
        foreach ($this->waiting as $id => [$fiber, $wait]) {
            if ($wait->isReady($now)
                || ($wait->read !== null && isset($read[(int) $wait->read]))
                || ($wait->write !== null && isset($write[(int) $wait->write]))) {
                unset($this->waiting[$id]);
                $this->queue->enqueue($fiber);
            }
        }
    }

    private function schedule(Fiber $fiber, mixed $wait): void
    {
        if ($fiber->isTerminated()) {
            return;
        }
        if ($wait instanceof Wait) {
            $this->waiting[spl_object_id($fiber)] = [$fiber, $wait];
        }
        else {
            $this->queue->enqueue($fiber);
        }
    }

    /**
     * Wrap the closure in a fiber and start it
     * @param Closure $closure
     * @param ...$args
     * @return void
     * @throws \Throwable
     */
    private function enqueue(Closure $closure, ...$args)
    {
        $fiber = new Fiber($closure);
        $this->schedule($fiber, $fiber->start(...$args));
    }
}
