<?php
namespace bronsted;

use Closure;
use Fiber;

/**
 * Call the closure when a given signal is raised
 * @param int $id
 * @param Closure $closure
 * @return void
 * @throws \Throwable
 */
function signal(int $id, Closure $closure)
{
    $done = false;
    pcntl_signal($id,  function() use($closure, &$done) {
        $closure();
        $done = true;
    });

    while(!$done) {
        Fiber::suspend();
        pcntl_signal_dispatch();
    }
}
