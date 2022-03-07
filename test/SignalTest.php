<?php

use bronsted\FiberLoop;
use PHPUnit\Framework\TestCase;

class SignalTest extends TestCase
{
    public function testOk()
    {
        $called = false;
        $loop = FiberLoop::instance();
        $loop->onSignal(SIGUSR1, function() use (&$called) {
            $called = true;
        });
        $loop->delay(0.1, function () {
            posix_kill(posix_getpid(), SIGUSR1);
        });
        $loop->run();
        $this->assertTrue($called);
    }
}
