<?php

use bronsted\FiberLoop;
use bronsted\test\StreamSelectStub;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class SelectFailureTest extends TestCase
{
    private array $streams = [];

    protected function setUp(): void
    {
        parent::setUp();
        require __DIR__ . '/fixtures/stream_select.php';
    }

    protected function tearDown(): void
    {
        foreach ($this->streams as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        parent::tearDown();
    }

    public function testSelectStreamReportsFailureAsNoReadyStreams()
    {
        [$reader, $writer] = $this->socketPair();
        fwrite($writer, 'x');
        StreamSelectStub::$failures = 1;

        $this->assertSame(0, bronsted\selectStream(read: $reader));
        $this->assertSame(0, StreamSelectStub::$failures);
        $this->assertSame(1, bronsted\selectStream(read: $reader));
        $this->assertSame('x', fread($reader, 1));
    }

    public function testLoopRetriesSelectionAfterTransientFailure()
    {
        [$reader, $writer] = $this->socketPair();
        fwrite($writer, 'x');
        $loop = new FiberLoop();
        $received = [];
        $loop->onReadableOnce($reader, function($stream) use (&$received) {
            $received[] = fread($stream, 1);
        });
        StreamSelectStub::$failures = 1;
        $loop->run();

        $this->assertSame(0, StreamSelectStub::$failures);
        $this->assertSame(['x'], $received);
    }

    /**
     * @dataProvider streamFunctions
     */
    public function testWatcherDoesNotInvokeCallbackOnSelectFailure(string $function)
    {
        [$stream, $peer] = $this->socketPair();
        fwrite($peer, 'x');
        $called = 0;
        $callback = function($stream) use (&$called) {
            $called += 1;
            fclose($stream);
        };
        $fiber = new Fiber(fn() => $function($stream, $callback));
        $fiber->start();
        StreamSelectStub::$failures = 1;
        $fiber->resume();

        $this->assertSame(0, StreamSelectStub::$failures);
        $this->assertSame(0, $called);
        $this->assertTrue($fiber->isSuspended());

        $fiber->resume();
        $this->assertSame(1, $called);
        $this->assertTrue($fiber->isTerminated());
    }

    public static function streamFunctions(): array
    {
        return [
            'repeating read' => ['bronsted\\readStream'],
            'once read' => ['bronsted\\readStreamOnce'],
            'repeating write' => ['bronsted\\writeStream'],
            'once write' => ['bronsted\\writeStreamOnce'],
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
