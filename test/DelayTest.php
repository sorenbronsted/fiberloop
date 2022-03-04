<?php

use bronsted\Defer;
use bronsted\FiberLoop;
use PHPUnit\Framework\TestCase;

class DelayTest extends TestCase
{
    private FiberLoop $loop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loop = FiberLoop::instance();
    }

    public function testOk()
    {
        $called = false;
        $this->loop->delay(0.5, function() use (&$called) {
            $called = true;
        });
        $this->loop->run();
        $this->assertTrue($called);
    }

    public function testWithInvalidSecondsOk()
    {
        $this->expectException(Exception::class);
        $this->loop->delay(-0.5, function() {});
    }
}
