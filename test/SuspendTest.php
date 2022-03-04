<?php

use bronsted\FiberLoop;
use bronsted\Suspend;
use PHPUnit\Framework\TestCase;

class SuspendTest extends TestCase
{
    private FiberLoop $loop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loop = FiberLoop::instance();
    }

    public function testOk()
    {
        $this->loop->defer(function() {
            $suspend = new Suspend();
            $this->loop->defer(fn() => $suspend->done());
            $suspend->wait();
        });
        $this->loop->run();
        $this->assertTrue(true);
    }

    public function testTimeout()
    {
        $this->loop->defer(function() {
            $this->expectException(Exception::class);
            $suspend = new Suspend(0.1);
            $suspend->wait();
        });
        $this->loop->run();
        $this->assertTrue(true);
    }
}
