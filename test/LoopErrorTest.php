<?php

use bronsted\FiberLoop;
use PHPUnit\Framework\TestCase;

class LoopErrorTest extends TestCase
{
    /**
     * @dataProvider callbackTypes
     */
    public function testCallbackExceptionPropagatesAndRemainingWorkCanResume(string $method)
    {
        $loop = new FiberLoop();
        $exception = new RuntimeException('callback failed');
        $called = 0;
        $completed = [];
        $callback = function() use ($loop, $exception, &$called, &$completed) {
            $called += 1;
            $loop->defer(function() use (&$completed) {
                $completed[] = 'deferred';
            });
            $loop->delay(0, function() use (&$completed) {
                $completed[] = 'delayed';
            });
            throw $exception;
        };
        $streams = [];

        try {
            if ($method === 'defer') {
                $loop->defer($callback);
            }
            elseif ($method === 'delay' || $method === 'repeat') {
                $loop->$method(0, $callback);
            }
            else {
                $streams = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                $this->assertNotFalse($streams);
                foreach ($streams as $stream) {
                    stream_set_blocking($stream, false);
                }
                fwrite($streams[1], 'ready');
                $loop->$method($streams[0], $callback);
            }

            try {
                $loop->run();
                $this->fail('The callback exception must propagate to the caller');
            }
            catch (RuntimeException $error) {
                $this->assertSame($exception, $error);
            }
            $this->assertSame(1, $called);
            $this->assertSame([], $completed);

            $loop->run();
            $this->assertEqualsCanonicalizing(['deferred', 'delayed'], $completed);
            $loop->run();
            $this->assertSame(1, $called);
            $this->assertCount(2, $completed);
        }
        finally {
            foreach ($streams as $stream) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }
    }

    public static function callbackTypes(): array
    {
        return [
            'defer' => ['defer'],
            'delay' => ['delay'],
            'repeat' => ['repeat'],
            'repeating read' => ['onReadable'],
            'once read' => ['onReadableOnce'],
            'repeating write' => ['onWriteable'],
            'once write' => ['onWriteableReady'],
        ];
    }
}
