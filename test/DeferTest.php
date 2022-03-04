<?php

use bronsted\FiberLoop;
use PHPUnit\Framework\TestCase;

class DeferTest extends TestCase
{
    public function testOk()
    {
        $called = false;
        $scheduler = new FiberLoop();
        $scheduler->defer(function() use (&$called) {
            $called = true;
        });
        $scheduler->run();
        $this->assertTrue($called);
    }
}
