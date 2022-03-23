<?php
namespace bronsted;

use Closure;
use Exception;
use Fiber;
use function usleep;

/**
 * Call the closure as soon as possible in the future
 * @param Closure $closure
 * @return void
 * @throws \Throwable
 */
function defer(Closure $closure)
{
    Fiber::suspend();
    $closure();
}

/**
 * Delay the call of the closure a given time in the future
 * @param float $seconds
 * @param Closure $closure
 * @return void
 * @throws \Throwable
 */
function delay(float $seconds, Closure $closure)
{
    if ($seconds < 0) {
        throw new Exception("Seconds must be positive");
    }
    $deadline = microtime(true) + $seconds;
    $done = false;
    Fiber::suspend();
    while (!$done) {
        if (microtime(true) < $deadline) {
            Fiber::suspend();
        }
        else {
            $done = true;
            $closure();
        }
    }
}

/**
 * Repeat calling the closure at every interval forever. If the closure returns a value
 * it will become the new interval and if the value is 0 the repeating stops.
 * @param float $seconds
 * @param Closure $closure
 * @return void
 * @throws \Throwable
 */
function repeat(float $seconds, Closure $closure)
{
    if ($seconds < 0) {
        throw new Exception("Seconds must be positive");
    }
    $deadline = microtime(true) + $seconds;
    Fiber::suspend();
    $done = false;
    while (!$done) {
        if (microtime(true) < $deadline) {
            Fiber::suspend();
        }
        else {
            $change = $closure($seconds);
            if ($change !== null) {
                $seconds = $change;
            }
            $now = microtime(true);
            $deadline = $now + $seconds;
            $done = ($deadline <= $now);
        }
    }
}

/**
 * This will sleep on every run. This can used to make loop sleep and there by keeping it from going
 * 100% cpu. This will degrade performance of the loop.
 * @param float $seconds
 * @param Closure $closure
 * @return void
 */
function sleep(int $microseconds, Closure $closure)
{
    if ($microseconds < 0) {
        throw new Exception("microseconds must be positive");
    }
    while(true) {
        Fiber::suspend();
        usleep($microseconds);
    }
}
