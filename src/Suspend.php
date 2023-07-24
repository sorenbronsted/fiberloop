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
 *      FiberLoop::instance()->onReadableOnce($stream, fn() => $suspend->done());
 *
 *      // wait for the suspending to complete
 *      $suspend->wait()
 */
class Suspend
{
    private bool $state;
    private float $deadline;

    public function __construct(float $timeout = 5)
    {
        $this->state = true;
        $this->deadline = microtime(true) + $timeout;
    }

    /**
     * Mark this suspending as done
     * @return void
     */
    public function done()
    {
        $this->state = false;
    }

    /**
     * Wait for this suspending to be done or throw an exception if timeout is reached
     * @return void
     * @throws \Throwable
     */
    public function wait()
    {
        while($this->state) {
            if (microtime(true) > $this->deadline) {
                throw new Exception('Timeout waiting');
            }
            Fiber::suspend();
        }
    }
}