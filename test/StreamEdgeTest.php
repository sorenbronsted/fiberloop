<?php

use bronsted\FiberLoop;
use PHPUnit\Framework\TestCase;

class StreamEdgeTest extends TestCase
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
    public function testNegativeTimeoutIsRejectedWithoutPoisoningLoop(string $method)
    {
        [$reader, $writer] = $this->socketPair();
        $loop = new FiberLoop();
        $called = false;
        try {
            $loop->$method($reader, function() use (&$called) {
                $called = true;
            }, -1);
            $this->fail('A negative timeout must be rejected');
        }
        catch (ValueError $error) {
            $this->assertSame('Timeout must be non-negative', $error->getMessage());
        }

        fwrite($writer, 'x');
        $received = null;
        $loop->onReadableOnce($reader, function($stream) use (&$received) {
            $received = fread($stream, 1);
        });
        $loop->run();

        $this->assertFalse($called);
        $this->assertSame('x', $received);
    }

    /**
     * @dataProvider streamWatchers
     */
    public function testStreamClosedBeforeReadyCallbackIsNotUsed(string $method)
    {
        [$stream, $peer] = $this->socketPair();
        fwrite($peer, 'ready');
        $loop = new FiberLoop();
        $called = false;
        $loop->$method($stream, function() use (&$called) {
            $called = true;
        });
        // The loop polls readiness before running this deferred close. The watcher
        // must check the resource again when its already-queued callback resumes.
        $loop->defer(fn() => fclose($stream));
        $loop->run();
        $loop->run();

        $this->assertFalse(is_resource($stream));
        $this->assertFalse($called);
    }

    /**
     * @dataProvider readableWatchers
     */
    public function testConsumedReadinessDoesNotInvokeCallbackUntilNewDataArrives(string $method)
    {
        [$reader, $writer] = $this->socketPair();
        fwrite($writer, 'x');
        $loop = new FiberLoop();
        $received = [];
        $loop->onReadableOnce($reader, function($stream) use ($loop, $writer, &$received) {
            $received[] = fread($stream, 1);
            $loop->delay(0, fn() => fwrite($writer, 'y'));
        });
        $loop->$method($reader, function($stream) use (&$received) {
            $received[] = fread($stream, 1);
            fclose($stream);
        });
        $loop->run();

        $this->assertSame(['x', 'y'], $received);
    }

    /**
     * @dataProvider readableWatchers
     */
    public function testPollingTimeoutDoesNotCancelIdleWatcher(string $method)
    {
        [$reader, $writer] = $this->socketPair();
        $loop = new FiberLoop();
        $received = [];
        $loop->$method($reader, function($stream) use (&$received) {
            $received[] = fread($stream, 1);
            fclose($stream);
        }, 1000);
        $loop->delay(0.01, fn() => fwrite($writer, 'x'));
        $loop->run();

        $this->assertSame(['x'], $received);
    }

    public static function readableWatchers(): array
    {
        return [
            'repeating read' => ['onReadable'],
            'once read' => ['onReadableOnce'],
        ];
    }

    public static function streamWatchers(): array
    {
        return self::readableWatchers() + [
            'repeating write' => ['onWriteable'],
            'once write' => ['onWriteableReady'],
        ];
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
}
