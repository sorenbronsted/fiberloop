<?php

use bronsted\FiberLoop;
use bronsted\Watcher;
use PHPUnit\Framework\TestCase;
use function bronsted\readStream;
use function bronsted\writeStream;

class StreamTest extends TestCase
{
    private FiberLoop $loop;

    /** @var list<resource> */
    private array $resources = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->loop = new FiberLoop();
        $this->resources = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->resources as $resource) {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }
        parent::tearDown();
    }

    /**
     * @return resource
     */
    private function open(string $mode = 'r+')
    {
        $resource = fopen(__DIR__ . '/data/dump', $mode);
        $this->resources[] = $resource;

        return $resource;
    }

    /**
     * @return array{0: resource, 1: resource}
     */
    private function socketPair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->resources[] = $pair[0];
        $this->resources[] = $pair[1];

        return $pair;
    }

    public function testOnReadableOk()
    {
        $called = 0;
        $eof = false;
        $resource = $this->open();

        $this->loop->onReadable($resource, function ($stream) use (&$called, &$eof) {
            $called += 1;
            if ($called === 1) {
                fgets($stream);
            } else {
                fread($stream, 100);
            }
            $eof = feof($stream);
        });

        $this->loop->run();
        // Six reads plus the final EOF notification.
        $this->assertSame(7, $called);
        $this->assertTrue($eof);
    }

    public function testReadStreamNotifiesOnceAtEof()
    {
        [$reader, $writer] = $this->socketPair();
        $received = '';
        $eofCalls = 0;

        $this->loop->onReadable($reader, function ($stream) use (&$received, &$eofCalls) {
            if (feof($stream)) {
                $eofCalls += 1;
                return;
            }
            $received .= (string) fread($stream, 100);
        });
        $this->loop->defer(function () use ($writer) {
            fwrite($writer, 'payload');
            fclose($writer);
        });

        $this->loop->run();

        $this->assertSame('payload', $received);
        $this->assertSame(1, $eofCalls);
    }

    public function testReadStreamReportsAlreadyReachedEofWithoutWaiting()
    {
        [$reader, $writer] = $this->socketPair();
        fclose($writer);
        // Consume the EOF so feof($reader) is true before the watcher is added.
        $this->assertSame('', fread($reader, 1));
        $this->assertTrue(feof($reader));

        $calls = 0;
        $this->loop->onReadable($reader, function () use (&$calls) {
            $calls += 1;
        });

        $this->loop->run();

        $this->assertSame(1, $calls);
    }

    public function testOnReadableOnInvalidResourceOk()
    {
        $called = 0;
        $watcher = $this->loop->onReadable(null, function () use (&$called) {
            $called += 1;
        });

        $this->loop->run();

        $this->assertSame(0, $called);
        $this->assertFalse($watcher->isActive());
    }

    public function testOnWriteableOk()
    {
        $called = 0;
        $resource = $this->open();

        $this->loop->onWriteable($resource, function ($stream) use (&$called) {
            $called += 1;
            if ($called > 1) {
                fclose($stream);
            } else {
                fwrite($stream, 'hello');
            }
        });

        $this->loop->run();
        $this->assertSame(2, $called);
    }

    public function testOnWriteableOnInvalidResourceOk()
    {
        $called = 0;
        $watcher = $this->loop->onWriteable(null, function () use (&$called) {
            $called += 1;
        });

        $this->loop->run();

        $this->assertSame(0, $called);
        $this->assertFalse($watcher->isActive());
    }

    public function testDuplicateReadWatcherThrows()
    {
        $resource = $this->open();
        $this->loop->onReadable($resource, fn() => null);

        $this->expectException(LogicException::class);
        $this->loop->onReadable($resource, fn() => null);
    }

    public function testDuplicateWriteWatcherThrows()
    {
        $resource = $this->open();
        $this->loop->onWriteable($resource, fn() => null);

        $this->expectException(LogicException::class);
        $this->loop->onWriteable($resource, fn() => null);
    }

    public function testCancelFreesResourceForNewWatcher()
    {
        $resource = $this->open();
        $first = $this->loop->onReadable($resource, fn() => null);
        $first->cancel();

        $second = $this->loop->onReadable($resource, fn() => null);

        $this->assertTrue($second->isActive());
        $this->assertFalse($first->isActive());
    }

    public function testReadAndWriteWatcherCanCoexist()
    {
        $resource = $this->open();

        $read = $this->loop->onReadable($resource, fn() => null);
        $write = $this->loop->onWriteable($resource, fn() => null);

        $this->assertTrue($read->isActive());
        $this->assertTrue($write->isActive());
    }

    public function testCancelWatcher()
    {
        [$read, $write] = $this->socketPair();
        $called = 0;

        $watcher = $this->loop->onReadable($read, function () use (&$called) {
            $called += 1;
        });

        $this->loop->defer(fn() => $watcher->cancel());
        $this->loop->run();

        $this->assertSame(0, $called);
        $this->assertFalse($watcher->isActive());
    }

    public function testCancelInCallback()
    {
        $called = 0;
        $watcher = null;
        $resource = $this->open();

        $watcher = $this->loop->onReadable($resource, function () use (&$called, &$watcher) {
            $called += 1;
            $watcher->cancel();
        });

        $this->loop->run();

        $this->assertSame(1, $called);
        $this->assertFalse($watcher->isActive());
    }

    public function testCancelAfterFcloseIsNoOp()
    {
        $called = 0;
        $resource = $this->open();
        $watcher = $this->loop->onReadable($resource, function () use (&$called) {
            $called += 1;
        });

        fclose($resource);
        $watcher->cancel();

        $this->loop->run();

        $this->assertSame(0, $called);
        $this->assertFalse($watcher->isActive());
    }

    public function testFcloseInCallbackIsNormalTermination()
    {
        $called = 0;
        $resource = $this->open();

        $this->loop->onReadable($resource, function ($stream) use (&$called) {
            $called += 1;
            fclose($stream);
        });

        $this->loop->run();
        $this->assertSame(1, $called);
    }

    public function testFcloseBeforeRunIsNormalTermination()
    {
        $called = 0;
        $resource = $this->open();
        $watcher = $this->loop->onReadable($resource, function () use (&$called) {
            $called += 1;
        });

        fclose($resource);
        $this->loop->run();

        $this->assertSame(0, $called);
        $this->assertFalse($watcher->isActive());
    }

    public function testFcloseFromAnotherFiberIsNormalTermination()
    {
        [$read, $write] = $this->socketPair();
        $called = 0;

        $watcher = $this->loop->onReadable($read, function () use (&$called) {
            $called += 1;
        });

        $this->loop->defer(fn() => fclose($read));
        $this->loop->run();

        $this->assertSame(0, $called);
        $this->assertFalse($watcher->isActive());
    }

    public function testFcloseDoesNotThrowCancelledException()
    {
        $finally = false;
        $cancelled = false;
        $resource = $this->open();

        $this->loop->defer(function () use ($resource, &$finally, &$cancelled) {
            try {
                readStream($resource, fn() => null);
            } catch (bronsted\CancelledException) {
                $cancelled = true;
            } finally {
                $finally = true;
            }
        });
        $this->loop->defer(fn() => fclose($resource));

        $this->loop->run();

        $this->assertFalse($cancelled);
        $this->assertTrue($finally);
    }

    public function testUnrefDoesNotKeepLoopAlive()
    {
        [$read, $write] = $this->socketPair();
        $called = 0;

        $watcher = $this->loop->onReadable($read, function () use (&$called) {
            $called += 1;
        });
        $watcher->unref();

        $this->loop->run();

        $this->assertSame(0, $called);
        $this->assertTrue($watcher->isActive());
        $this->assertFalse($watcher->isReferenced());
    }

    public function testRefKeepsLoopAlive()
    {
        [$read, $write] = $this->socketPair();
        $called = 0;

        $watcher = $this->loop->onReadable($read, function () use (&$called) {
            $called += 1;
        });

        $this->assertTrue($watcher->isReferenced());

        $watcher->unref();
        $watcher->ref();

        $this->assertTrue($watcher->isReferenced());
    }

    public function testClosedWatcherIsPrunedBeforeNewRegistration()
    {
        $first = $this->open();
        $firstWatcher = $this->loop->onReadable($first, fn() => null);

        fclose($first);

        $second = $this->open();
        $secondWatcher = $this->loop->onReadable($second, fn() => null);

        $this->assertTrue($secondWatcher->isActive());
        $this->assertFalse($firstWatcher->isActive());
    }

    public function testCancelWriteWatcherUnregistersWriteMap()
    {
        $resource = $this->open();
        $watcher = $this->loop->onWriteable($resource, fn() => null);

        $watcher->cancel();

        $this->assertFalse($watcher->isActive());
    }

    public function testWriteStreamReturnsWhenClosedWhileWaiting()
    {
        $resource = $this->open();

        $this->loop->defer(function () use ($resource) {
            writeStream($resource, fn() => null);
        });
        $this->loop->defer(fn() => fclose($resource));

        $this->loop->run();

        $this->assertFalse(is_resource($resource));
    }

    public function testSelectUsesTimerAsTimeout()
    {
        [$read] = $this->socketPair();
        $called = 0;

        $watcher = $this->loop->onReadable($read, function () use (&$called) {
            $called += 1;
        });
        $this->loop->delay(0.02, fn() => $watcher->cancel());

        $this->loop->run();

        $this->assertSame(0, $called);
        $this->assertFalse($watcher->isActive());
    }

    public function testWriteStreamWorksAfterPeerHalfClose()
    {
        [$reader, $writer] = $this->socketPair();

        stream_socket_shutdown($writer, STREAM_SHUT_WR);

        // Consume the EOF so feof($reader) becomes true.
        $this->assertSame('', fread($reader, 1));
        $this->assertTrue(feof($reader));

        $called = 0;
        $this->loop->defer(function () use ($reader, &$called) {
            writeStream($reader, function ($stream) use (&$called) {
                $called += 1;
                fwrite($stream, 'reply');
                fclose($stream);
            });
        });

        $this->loop->run();

        $this->assertSame(1, $called);
        $this->assertSame('reply', fread($writer, 10));
    }
}
