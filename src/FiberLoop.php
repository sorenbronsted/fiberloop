<?php

namespace bronsted;

use Closure;
use Fiber;
use SplQueue;
use function intval;
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
     * @param int $timeout in microseconds
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
     * @param int $timeout in microseconds
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
     * Call the closure when a matching signal is received
     * @param int $id
     * @param Closure $closure
     * @return void
     */
    public function onSignal(int $id, Closure $closure)
    {
        $this->enqueue(signal(...), $id, $closure);
    }

    /**
     * Run the loop until there are no more fibers to run (the queue is empty)
     * @return void
     */
    public function run()
    {
        while (!$this->queue->isEmpty()) {
            $fiber = $this->queue->dequeue();
            if ($fiber->isSuspended()) {
                $fiber->resume();
            }
            if (!$fiber->isTerminated()) {
                $this->queue->enqueue($fiber);
            }
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
        $this->queue->enqueue($fiber);
        $fiber->start(...$args);
    }
}
