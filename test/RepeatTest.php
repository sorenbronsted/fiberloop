<?php

use bronsted\FiberLoop;
use PHPUnit\Framework\TestCase;

class RepeatTest extends TestCase
{
    private FiberLoop $loop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loop = FiberLoop::instance();
    }

    public function testOk()
    {
        $called = 0;
        $this->loop->repeat(0.1, function($interval) use (&$called) {
            $called += 1;
            if ($called > 1) {
                return 0;
            }
            return $interval;
        });
        $this->loop->run();
        $this->assertEquals(2, $called);
    }

    public function testWithInvalidSecondsOk()
    {
        $this->expectException(Exception::class);
        $this->loop->repeat(-0.5, function() {});
    }
}
