<?php

use bronsted\FiberLoop;
use PHPUnit\Framework\TestCase;

class SignalTest extends TestCase
{
    public function testOk()
    {
        $called = false;
        $scheduler = new FiberLoop();
        $scheduler->onSignal(SIGUSR1, function() use (&$called) {
            $called = true;
        });
        $scheduler->delay(0.1, function () {
            posix_kill(posix_getpid(), SIGUSR1);
        });
        $scheduler->run();
        $this->assertTrue($called);
    }
}