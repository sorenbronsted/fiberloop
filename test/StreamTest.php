<?php

use bronsted\FiberLoop;
use PHPUnit\Framework\TestCase;

class StreamTest extends TestCase
{
    private FiberLoop $loop;
    private $resource = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loop = FiberLoop::instance();
        $this->resource = fopen(__DIR__ . '/data/dump', 'r+');
    }

    public function testOnReadableOk()
    {
        $called = 0;
        $this->loop->onReadable($this->resource, function($stream) use (&$called) {
            $called += 1;
            if ($called == 1) {
                fgets($stream);
            }
            else {
                fread($stream, 100);
            }
        });
        $this->loop->run();
        $this->assertEquals(6, $called);
    }

    public function testOnReadableReadyOk()
    {
        $called = 0;
        $this->loop->onReadableOnce($this->resource, function() use (&$called) {
            $called += 1;
        });
        $this->loop->run();
        $this->assertEquals(1, $called);
    }

    public function testOnReadableReadyOnInvalidResourceOk()
    {
        $called = 0;
        $this->loop->onReadableOnce(null, function() use (&$called) {
            $called += 1;
        });
        $this->loop->run();
        $this->assertEquals(0, $called);
    }

    public function testOnWriteableOk()
    {
        $called = 0;
        $this->loop->onWriteable($this->resource, function($stream) use (&$called) {
            $called += 1;
            if ($called > 1) {
                fclose($stream);
            }
            else {
                fwrite($stream, 'hello');
            }
        });
        $this->loop->run();
        $this->assertEquals(2,$called);
    }

    public function testOnWriteableReadyOk()
    {
        $called = 0;
        $this->loop->onWriteableReady($this->resource, function($stream) use (&$called) {
            $called += 1;
        });
        $this->loop->run();
        $this->assertEquals(1, $called);
    }

    public function testOnWriteableReadyOnInvalidResource()
    {
        $called = 0;
        $this->loop->onWriteableReady(0, function() use (&$called) {
            $called += 1;
        });
        $this->loop->run();
        $this->assertEquals(0, $called);
    }

}
