<?php

use bronsted\FiberLoop;
use bronsted\Suspend;
use PHPUnit\Framework\TestCase;

class LoopTest extends TestCase
{
    private array $streams = [];

    protected function tearDown(): void
    {
        foreach ($this->streams as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        parent::tearDown();
    }

    /**
     * @dataProvider readableWatchers
     */
    public function testStreamTimeoutDoesNotBlockTimer(string $method)
    {
        [$reader, $writer] = $this->socketPair();
        $loop = new FiberLoop();
        $elapsed = null;
        $received = null;
        $start = microtime(true);

        $loop->$method($reader, function($stream) use (&$received) {
            $received = fread($stream, 1);
            fclose($stream);
        }, 500000);
        $loop->delay(0.01, function() use ($writer, $start, &$elapsed) {
            $elapsed = microtime(true) - $start;
            fwrite($writer, 'x');
        });
        $loop->run();

        $this->assertSame('x', $received);
        $this->assertLessThan(0.25, $elapsed);
    }

    public static function readableWatchers(): array
    {
        return [
            'repeating' => ['onReadable'],
            'once' => ['onReadableOnce'],
        ];
    }

    public function testIdleStreamDoesNotBlockAnotherReadyStream()
    {
        [$idleReader, $idleWriter] = $this->socketPair();
        [$readyReader, $readyWriter] = $this->socketPair();
        fwrite($readyWriter, 'ready');
        $loop = new FiberLoop();
        $elapsed = null;
        $start = microtime(true);

        $loop->onReadableOnce($idleReader, fn($stream) => fread($stream, 1), 500000);
        $loop->onReadableOnce($readyReader, function($stream) use ($idleWriter, $start, &$elapsed) {
            $elapsed = microtime(true) - $start;
            fread($stream, 5);
            fwrite($idleWriter, 'x');
        });
        $loop->run();

        $this->assertNotNull($elapsed);
        $this->assertLessThan(0.25, $elapsed);
    }

    public function testWritableOnceAfterReadEof()
    {
        [$server, $client] = $this->halfClosedPair();
        $loop = new FiberLoop();
        $called = 0;

        $loop->onWriteableReady($server, function($stream) use (&$called) {
            $called += 1;
            fwrite($stream, 'response');
        });
        $loop->run();

        $this->assertSame(1, $called);
        $this->assertSame('response', fread($client, 8));
    }

    public function testWritableRepeatsAfterReadEof()
    {
        [$server, $client] = $this->halfClosedPair();
        $loop = new FiberLoop();
        $called = 0;

        $loop->onWriteable($server, function($stream) use (&$called) {
            $called += 1;
            fwrite($stream, 'x');
            if ($called == 2) {
                fclose($stream);
            }
        });
        $loop->run();

        $this->assertSame(2, $called);
        $this->assertSame('xx', fread($client, 2));
    }

    public function testIdleTimerDoesNotBusyWait()
    {
        if (!function_exists('getrusage')) {
            $this->markTestSkipped('CPU usage measurement requires getrusage');
        }
        $loop = new FiberLoop();
        $called = false;
        $loop->delay(0.2, function() use (&$called) {
            $called = true;
        });
        $start = microtime(true);
        $cpuStart = $this->cpuTime();
        $loop->run();
        $cpu = $this->cpuTime() - $cpuStart;
        $elapsed = microtime(true) - $start;

        $this->assertTrue($called);
        $this->assertGreaterThanOrEqual(0.19, $elapsed);
        $this->assertLessThan($elapsed / 2, $cpu);
    }

    public function testTimerCanClosePendingStream()
    {
        [$reader] = $this->socketPair();
        $loop = new FiberLoop();
        $called = false;
        $loop->onReadableOnce($reader, function() use (&$called) {
            $called = true;
        });
        $loop->delay(0.01, fn() => fclose($reader));
        $loop->run();

        $this->assertFalse($called);
    }

    public function testIdleStreamDoesNotBusyWait()
    {
        if (!function_exists('getrusage')) {
            $this->markTestSkipped('CPU usage measurement requires getrusage');
        }
        [$reader, $writer] = $this->socketPair();
        $loop = new FiberLoop();
        $loop->onReadableOnce($reader, fn($stream) => fread($stream, 1));
        $loop->delay(0.2, fn() => fwrite($writer, 'x'));
        $start = microtime(true);
        $cpuStart = $this->cpuTime();
        $loop->run();
        $cpu = $this->cpuTime() - $cpuStart;
        $elapsed = microtime(true) - $start;

        $this->assertGreaterThanOrEqual(0.19, $elapsed);
        $this->assertLessThan($elapsed / 2, $cpu);
    }

    public function testMultipleWatchersCanShareStream()
    {
        [$reader, $writer] = $this->socketPair();
        fwrite($writer, 'x');
        $loop = new FiberLoop();
        $called = 0;
        $callback = function() use (&$called) {
            $called += 1;
        };
        $loop->onReadableOnce($reader, $callback);
        $loop->onReadableOnce($reader, $callback);
        $loop->run();

        $this->assertSame(2, $called);
    }

    public function testPersistentStreamCanBeWatched()
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($server);
        $this->streams[] = $server;
        $client = pfsockopen('tcp://' . stream_socket_get_name($server, false));
        $this->assertNotFalse($client);
        $this->streams[] = $client;
        $peer = stream_socket_accept($server, 1);
        $this->assertNotFalse($peer);
        $this->streams[] = $peer;
        stream_set_blocking($client, false);
        fwrite($peer, 'ready');
        $loop = new FiberLoop();
        $received = null;
        $loop->onReadableOnce($client, function($stream) use (&$received) {
            $received = fread($stream, 5);
        });
        $loop->run();

        $this->assertSame('ready', $received);
    }

    public function testCallbackCanRegisterStreamWatcher()
    {
        [$reader, $writer] = $this->socketPair();
        $loop = new FiberLoop();
        $received = null;
        $loop->defer(function() use ($loop, $reader, $writer, &$received) {
            $loop->onReadableOnce($reader, function($stream) use (&$received) {
                $received = fread($stream, 1);
            });
            $loop->delay(0.01, fn() => fwrite($writer, 'x'));
        });
        $loop->run();

        $this->assertSame('x', $received);
    }

    public function testStreamCallbackCanWaitForAnotherFiber()
    {
        [$reader, $writer] = $this->socketPair();
        fwrite($writer, 'x');
        $loop = new FiberLoop();
        $elapsed = null;
        $loop->onReadableOnce($reader, function() use ($loop, &$elapsed) {
            $suspend = new Suspend(0.5);
            $start = microtime(true);
            $loop->delay(0.01, fn() => $suspend->done());
            $suspend->wait();
            $elapsed = microtime(true) - $start;
        });
        $loop->run();

        $this->assertNotNull($elapsed);
        $this->assertLessThan(0.25, $elapsed);
    }

    public function testPlainSuspensionStillResumesOnNextTurn()
    {
        $loop = new FiberLoop();
        $events = [];
        $loop->defer(function() use (&$events) {
            $events[] = 'before';
            Fiber::suspend();
            $events[] = 'after';
        });
        $loop->defer(function() use (&$events) {
            $events[] = 'other';
        });
        $loop->run();

        $this->assertSame(['before', 'other', 'after'], $events);
    }

    public function testCallbackCanRunLoopRecursively()
    {
        $loop = new FiberLoop();
        $events = [];
        $loop->defer(function() use ($loop, &$events) {
            $events[] = 'before';
            $loop->run();
            $events[] = 'after';
        });
        $loop->defer(function() use (&$events) {
            $events[] = 'other';
        });
        $loop->run();

        $this->assertSame(['before', 'other', 'after'], $events);
    }

    private function socketPair(): array
    {
        $streams = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($streams);
        foreach ($streams as $stream) {
            stream_set_blocking($stream, false);
            $this->streams[] = $stream;
        }
        return $streams;
    }

    private function halfClosedPair(): array
    {
        [$server, $client] = $this->socketPair();
        fwrite($client, 'request');
        stream_socket_shutdown($client, STREAM_SHUT_WR);
        $this->assertSame('request', stream_get_contents($server));
        $this->assertTrue(feof($server));
        return [$server, $client];
    }

    private function cpuTime(): float
    {
        $usage = getrusage();
        return $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1000000
            + $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1000000;
    }
}
