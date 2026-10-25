<?php
namespace bronsted;

use Closure;
use Exception;
use Fiber;
use function microtime;

/**
 * Call the closure as soon as possible in the future
 *
 * @param Closure $closure
 * @return void
 * @throws \Throwable
 */
function defer(Closure $closure): void
{
    Fiber::suspend(Wait::ready());
    $closure();
}

/**
 * Delay the call of the closure a given time in the future
 *
 * @param float $seconds
 * @param Closure $closure
 * @return void
 * @throws \Throwable
 */
function delay(float $seconds, Closure $closure): void
{
    if ($seconds < 0) {
        throw new Exception("Seconds must be positive");
    }

    $deadline = microtime(true) + $seconds;
    Fiber::suspend(Wait::timer($deadline));
    $closure();
}

/**
 * Repeat calling the closure at every interval forever. If the closure returns a value
 * it will become the new interval and if the value is 0 the repeating stops.
 *
 * @param float $seconds
 * @param Closure $closure
 * @return void
 * @throws \Throwable
 */
function repeat(float $seconds, Closure $closure): void
{
    if ($seconds < 0) {
        throw new Exception("Seconds must be positive");
    }

    $deadline = microtime(true) + $seconds;

    while (true) {
        Fiber::suspend(Wait::timer($deadline));

        $change = $closure($seconds);
        if ($change !== null) {
            $seconds = (float) $change;
        }

        if ($seconds <= 0) {
            return;
        }

        $deadline = microtime(true) + $seconds;
    }
}
