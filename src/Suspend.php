<?php

namespace bronsted;

use Exception;
use Fiber;
use function microtime;

/**
 * With this class you can wait until another fiber mark this suspending done.
 * A typical scenario in a fiber:
 *
 *      // Create the object
 *      $suspend = new Suspend();
 *
 *      // When data becomes available then mark this suspending done
 *      FiberLoop::instance()->onReadable($stream, function ($stream) use ($suspend) {
 *          fclose($stream);
 *          $suspend->done();
 *      });
 *
 *      // wait for the suspending to complete
 *      $suspend->wait()
 */
class Suspend
{
    private bool $done;
    private float $deadline;
    private ?Fiber $fiber = null;
    private ?FiberLoop $loop = null;

    public function __construct(float $timeout = 5)
    {
        $this->done = false;
        $this->deadline = microtime(true) + $timeout;
    }

    /**
     * Mark this suspending as done
     * @return void
     */
    public function done(): void
    {
        $this->done = true;

        if ($this->fiber !== null && $this->loop !== null) {
            $this->loop->wake($this->fiber);
        }
    }

    /**
     * Wait for this suspending to be done or throw an exception if timeout is reached
     * @return void
     * @throws \Throwable
     */
    public function wait(): void
    {
        if ($this->done) {
            return;
        }

        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            throw new Exception('Suspend::wait must be called inside a fiber');
        }

        if (microtime(true) > $this->deadline) {
            throw new Exception('Timeout waiting');
        }

        $this->fiber = $fiber;
        $this->loop = FiberLoop::current();

        try {
            Fiber::suspend(Wait::park($this->deadline));
        } finally {
            $this->fiber = null;
            $this->loop = null;
        }

        if (!$this->done) {
            throw new Exception('Timeout waiting');
        }
    }
}
