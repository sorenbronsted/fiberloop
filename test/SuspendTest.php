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

    public function testDoneBeforeWait()
    {
        $this->loop->defer(function() {
            $suspend = new Suspend();
            $suspend->done();
            $suspend->wait();
            $this->assertTrue(true);
        });
        $this->loop->run();
    }

    public function testWaitOutsideFiberThrows()
    {
        $this->expectException(Exception::class);
        (new Suspend())->wait();
    }

    public function testExpiredBeforeWaitThrows()
    {
        $this->expectException(Exception::class);

        $this->loop->defer(function() {
            (new Suspend(-1))->wait();
        });
        $this->loop->run();
    }
}
