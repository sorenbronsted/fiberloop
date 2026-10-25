<?php

use bronsted\CancelledException;
use bronsted\FiberLoop;
use bronsted\Suspend;
use bronsted\Watcher;
use bronsted\WatcherState;
use PHPUnit\Framework\TestCase;
use function bronsted\readStream;

class CancelTest extends TestCase
{
    /** @var list<resource> */
    private array $resources = [];

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
     * @return array{0: resource, 1: resource}
     */
    private function socketPair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->resources[] = $pair[0];
        $this->resources[] = $pair[1];

        return $pair;
    }

    public function testStopRunsFinallyOnSuspendedFiber()
    {
        $loop = new FiberLoop();
        [$read] = $this->socketPair();
        $finally = false;

        $loop->defer(function () use ($read, &$finally) {
            try {
                readStream($read, fn() => null);
            } finally {
                $finally = true;
            }
        });
        $loop->defer(fn() => $loop->stop());

        $loop->run();

        $this->assertTrue($finally);
    }

    public function testWatcherCancelThrowsCancelledExceptionAndRunsFinally()
    {
        $loop = new FiberLoop();
        [$read] = $this->socketPair();
        $caught = false;
        $finally = false;

        $state = new WatcherState(1_000_000, $read, 'read', false);
        $watcher = new Watcher($loop, $state);

        $loop->defer(function () use ($read, $watcher, &$caught, &$finally) {
            try {
                readStream($read, fn() => null, $watcher);
            } catch (CancelledException) {
                $caught = true;
            } finally {
                $finally = true;
            }
        });
        $loop->defer(fn() => $watcher->cancel());

        $loop->run();

        $this->assertTrue($caught);
        $this->assertTrue($finally);
    }

    public function testStopCancelsParkedSuspend()
    {
        $loop = new FiberLoop();
        $finally = false;
        $caught = false;

        $loop->defer(function () use (&$finally, &$caught) {
            try {
                (new Suspend(30))->wait();
            } catch (CancelledException) {
                $caught = true;
            } finally {
                $finally = true;
            }
        });
        $loop->defer(fn() => $loop->stop());

        $loop->run();

        $this->assertTrue($caught);
        $this->assertTrue($finally);
    }

    public function testCancelTwiceIsNoOp()
    {
        $loop = new FiberLoop();
        [$read] = $this->socketPair();

        $watcher = $loop->onReadable($read, fn() => null);
        $watcher->cancel();
        $watcher->cancel();

        $this->assertFalse($watcher->isActive());
    }

    public function testStopInsideFiberThenSuspendCancelsFiber()
    {
        $loop = new FiberLoop();
        [$read] = $this->socketPair();
        $caught = false;
        $finally = false;

        $loop->defer(function () use ($loop, $read, &$caught, &$finally) {
            try {
                $loop->stop();
                readStream($read, fn() => null);
            } catch (CancelledException) {
                $caught = true;
            } finally {
                $finally = true;
            }
        });

        $loop->run();

        $this->assertTrue($caught);
        $this->assertTrue($finally);
    }
}
