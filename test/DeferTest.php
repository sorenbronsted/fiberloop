<?php

use bronsted\FiberLoop;
use PHPUnit\Framework\TestCase;

class DeferTest extends TestCase
{
    public function testOk()
    {
        $called = false;
        $loop = FiberLoop::instance();
        $loop->defer(function() use (&$called) {
            $called = true;
        });
        $loop->run();
        $this->assertTrue($called);
    }
}
