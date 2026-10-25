<?php

use bronsted\FiberLoop;
use bronsted\FiberState;
use bronsted\TimerEntry;
use bronsted\Wait;
use bronsted\Watcher;
use bronsted\WatcherState;
use PHPUnit\Framework\TestCase;

/**
 * Exercises defensive and internal branches that cannot be reached through
 * the normal public API.
 */
class InternalTest extends TestCase
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

    private function invoke(object $object, string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod($object, $method))->invoke($object, ...$args);
    }

    private function property(object $object, string $name): mixed
    {
        return (new ReflectionProperty($object, $name))->getValue($object);
    }

    private function setProperty(object $object, string $name, mixed $value): void
    {
        (new ReflectionProperty($object, $name))->setValue($object, $value);
    }

    private function suspendedFiberState(): FiberState
    {
        $fiber = new Fiber(fn() => Fiber::suspend());
        $fiber->start();

        return new FiberState($fiber);
    }

    public function testCancelUnboundWatcher()
    {
        $loop = new FiberLoop();
        $state = new WatcherState(1, $this->open(), 'read', false);
        $watcher = new Watcher($loop, $state);

        $watcher->cancel();

        $this->assertFalse($watcher->isActive());
    }

    public function testCancelForTerminatedFiber()
    {
        $loop = new FiberLoop();
        $fiber = new Fiber(fn() => null);
        $fiber->start();

        $state = new WatcherState(1, $this->open(), 'read', false);
        $state->fiber = new FiberState($fiber);
        $watcher = new Watcher($loop, $state);

        $watcher->cancel();

        $this->assertFalse($watcher->isActive());
    }

    public function testWakeUnknownFiber()
    {
        $loop = new FiberLoop();
        $fiber = new Fiber(fn() => null);

        $loop->wake($fiber);

        $this->assertFalse($fiber->isStarted());
    }

    public function testWakeUnregistersActiveWatcher()
    {
        $loop = new FiberLoop();
        [$read] = $this->socketPair();
        $called = 0;

        $watcher = $loop->onReadable($read, function () use (&$called) {
            $called += 1;
        });

        $states = $this->property($loop, 'fibers');
        $state = reset($states);
        $loop->wake($state->fiber);
        $watcher->cancel();

        $loop->run();

        $this->assertSame(1, $called);
        $this->assertFalse($watcher->isActive());
    }

    public function testHandleSuspensionReturnsForTerminatedState()
    {
        $loop = new FiberLoop();
        $state = new FiberState(new Fiber(fn() => null));
        $state->terminated = true;

        $this->invoke($loop, 'handleSuspension', $state, Wait::ready());

        $this->assertTrue($state->terminated);
    }

    public function testHandleSuspensionTerminatesTerminatedFiber()
    {
        $loop = new FiberLoop();
        $fiber = new Fiber(fn() => null);
        $fiber->start();

        $state = new FiberState($fiber);

        $this->invoke($loop, 'handleSuspension', $state, Wait::ready());

        $this->assertTrue($state->terminated);
    }

    public function testMarkReadableIgnoresUnknownWatcher()
    {
        $loop = new FiberLoop();

        $this->invoke($loop, 'markReadable', [123_456 => $this->open()]);

        $this->assertTrue(true);
    }

    public function testMarkReadablePrunesClosedWatcher()
    {
        $loop = new FiberLoop();
        $resource = new stdClass();
        $state = $this->suspendedFiberState();
        $watcher = new WatcherState(1, $resource, 'read', false);
        $watcher->resourceId = 42;
        $watcher->active = true;
        $watcher->fiber = $state;

        $this->setProperty($loop, 'readers', [42 => $watcher]);
        $this->invoke($loop, 'markReadable', [42 => new stdClass()]);

        $this->assertTrue($watcher->active);

        $this->invoke($loop, 'markReadable', [42 => $resource]);

        $this->assertFalse($watcher->active);
        $this->assertTrue($watcher->closed);
    }

    public function testMarkWriteableIgnoresUnknownWatcher()
    {
        $loop = new FiberLoop();

        $this->invoke($loop, 'markWriteable', [123_456 => $this->open()]);

        $this->assertTrue(true);
    }

    public function testMarkWriteablePrunesClosedWatcher()
    {
        $loop = new FiberLoop();
        $resource = new stdClass();
        $state = $this->suspendedFiberState();
        $watcher = new WatcherState(1, $resource, 'write', false);
        $watcher->resourceId = 42;
        $watcher->active = true;
        $watcher->fiber = $state;

        $this->setProperty($loop, 'writers', [42 => $watcher]);
        $this->invoke($loop, 'markWriteable', [42 => new stdClass()]);

        $this->assertTrue($watcher->active);

        $this->invoke($loop, 'markWriteable', [42 => $resource]);

        $this->assertFalse($watcher->active);
        $this->assertTrue($watcher->closed);
    }

    public function testRegisterWatcherClosesProvidedWatcherForNonResource()
    {
        $loop = new FiberLoop();
        $state = new FiberState(new Fiber(fn() => null));
        $provided = new WatcherState(1, null, 'read', false);
        $watcher = new Watcher($loop, $provided);

        $this->invoke($loop, 'registerWatcher', $state, Wait::read(null, $watcher));

        $this->assertTrue($provided->closed);
    }

    public function testRegisterWatcherClosesTransientWatcherForNonResource()
    {
        $loop = new FiberLoop();
        $state = new FiberState(new Fiber(fn() => null));
        $transient = new WatcherState(1, null, 'read', true);
        $state->watcher = $transient;

        $this->invoke($loop, 'registerWatcher', $state, Wait::read(null));

        $this->assertTrue($transient->closed);
    }

    public function testRegisterWatcherWithClosedWatcher()
    {
        $loop = new FiberLoop();
        $resource = $this->open();
        $state = new FiberState(new Fiber(fn() => null));
        $watcherState = new WatcherState(1, $resource, 'read', false);
        $watcherState->closed = true;
        $watcher = new Watcher($loop, $watcherState);

        $this->invoke($loop, 'registerWatcher', $state, Wait::read($resource, $watcher));

        $this->assertFalse($watcherState->active);
    }

    public function testRegisterWatcherWithCancelledWatcher()
    {
        $loop = new FiberLoop();
        $resource = $this->open();
        $state = new FiberState(new Fiber(fn() => null));
        $watcherState = new WatcherState(1, $resource, 'read', false);
        $watcherState->cancelled = true;
        $watcher = new Watcher($loop, $watcherState);

        $this->invoke($loop, 'registerWatcher', $state, Wait::read($resource, $watcher));

        $this->assertTrue($state->cancelRequested);
    }

    public function testRegisterWatcherWithMismatchedWatcherThrows()
    {
        $loop = new FiberLoop();
        $resource = $this->open();
        $state = new FiberState(new Fiber(fn() => null));
        $watcherState = new WatcherState(1, $resource, 'write', false);
        $watcher = new Watcher($loop, $watcherState);

        $this->expectException(LogicException::class);

        $this->invoke($loop, 'registerWatcher', $state, Wait::read($resource, $watcher));
    }

    public function testRegisterWatcherReplacesTransientWatcher()
    {
        $loop = new FiberLoop();
        $first = $this->open();
        $second = $this->open();
        $state = new FiberState(new Fiber(fn() => null));
        $transient = new WatcherState(1, $first, 'read', true);
        $transient->resourceId = get_resource_id($first);
        $transient->active = true;
        $transient->fiber = $state;
        $state->watcher = $transient;

        $this->invoke($loop, 'registerWatcher', $state, Wait::read($second));

        $this->assertTrue($transient->closed);
        $this->assertNotSame($transient, $state->watcher);
        $this->assertSame(get_resource_id($second), $state->watcher->resourceId);
    }

    public function testRegisterWatcherRejectsCancelledTransientWatcher()
    {
        $loop = new FiberLoop();
        $resource = $this->open();
        $state = new FiberState(new Fiber(fn() => null));
        $transient = new WatcherState(1, $resource, 'read', true);
        $transient->cancelled = true;
        $state->watcher = $transient;

        $this->invoke($loop, 'registerWatcher', $state, Wait::read($resource));

        $this->assertTrue($state->cancelRequested);
    }

    public function testScheduleTimerReplacesPreviousTimer()
    {
        $loop = new FiberLoop();
        $state = new FiberState(new Fiber(fn() => null));
        $now = microtime(true);

        $this->invoke($loop, 'scheduleTimer', $state, $now + 10);
        $first = $state->timer;

        $this->invoke($loop, 'scheduleTimer', $state, $now + 20);

        $this->assertTrue($first->cancelled);
        $this->assertSame($state->timer->deadline, $this->invoke($loop, 'nextTimerDeadline'));
    }

    public function testTerminateFiberTwiceAndCancelsTimer()
    {
        $loop = new FiberLoop();
        $fiber = new Fiber(fn() => null);
        $fiber->start();

        $state = new FiberState($fiber);
        $timer = new TimerEntry(microtime(true) + 10, $state);
        $state->timer = $timer;

        $this->invoke($loop, 'terminateFiber', $state);

        $this->assertTrue($timer->cancelled);
        $this->assertNull($state->timer);

        $this->invoke($loop, 'terminateFiber', $state);

        $this->assertTrue($state->terminated);
    }

    public function testRequestCancelIgnoresTerminatedFiber()
    {
        $loop = new FiberLoop();
        $fiber = new Fiber(fn() => null);
        $fiber->start();

        $state = new FiberState($fiber);

        $this->invoke($loop, 'requestCancel', $state);

        $this->assertFalse($state->cancelRequested);
    }

    public function testHasReferencedWorkWithReadyFiber()
    {
        $loop = new FiberLoop();
        $state = new FiberState(new Fiber(fn() => null));

        $ready = $this->property($loop, 'ready');
        $ready->enqueue($state);

        $this->assertTrue($this->invoke($loop, 'hasReferencedWork'));
    }

    public function testProcessReadySkipsNonSuspendedFiber()
    {
        $loop = new FiberLoop();
        $fiber = new Fiber(fn() => null);
        $fiber->start();

        $state = new FiberState($fiber);
        $state->queued = true;

        $ready = $this->property($loop, 'ready');
        $ready->enqueue($state);

        $this->invoke($loop, 'processReady');

        $this->assertFalse($state->queued);
    }

    public function testSleepUntilPastDeadlineReturnsImmediately()
    {
        $loop = new FiberLoop();

        $this->invoke($loop, 'sleepUntil', microtime(true) - 1);

        $this->assertTrue(true);
    }
}
