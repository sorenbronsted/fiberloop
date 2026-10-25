# Fiberloop

This package implements [coroutines](https://en.wikipedia.org/wiki/Coroutine) by utilizing PHP
[fibers](https://www.php.net/manual/en/language.fibers.php).

On top of this I have built [Psr-Http](https://github.com/sorenbronsted/psr-http), which is a PSR
compliant HTTP web server and client.

Using coroutines enables you to write code that handles multiple tasks in PHP's single-threaded
execution model. This makes your program efficient and able to handle more work.

## Installation

This package can be installed with [Composer](https://getcomposer.org/):

```bash
composer require bronsted/fiberloop
```

The library requires PHP 8.1 or newer. Development tooling (PHPUnit 12) requires PHP 8.3 or newer.

## Quick start

```php
use bronsted\FiberLoop;

$loop = new FiberLoop();

$loop->defer(function () {
    echo "runs as soon as possible\n";
});

$loop->delay(0.5, function () {
    echo "runs half a second later\n";
});

$loop->run();
```

`FiberLoop::instance()` returns a shared singleton if you do not want to pass the loop around.

## Timers

### `defer(Closure $closure)`

Runs the closure as soon as possible, but not before control has returned to the loop.

### `delay(float $seconds, Closure $closure)`

Runs the closure once after `$seconds`. A negative delay throws before the loop starts waiting.

### `repeat(float $interval, Closure $closure)`

Runs the closure every `$interval` seconds. The closure receives the current interval and can return
a new interval. Returning `null` keeps the current interval. Returning `0` or a negative value stops
the repetition.

```php
$loop->repeat(1.0, function (float $interval) {
    echo "tick\n";
    return $interval; // keep going
});
```

## Streams

The normal pattern is to open a resource, register a watcher, and close the resource when the work
is done. `fclose()` is normal termination: the watcher is removed automatically, and `run()` returns
when no other referenced work remains.

### `onReadable(mixed $resource, Closure $closure): Watcher`

Calls the closure every time the resource becomes readable.

```php
$stream = fopen(__DIR__ . '/input.txt', 'r');

$loop->onReadable($stream, function ($stream) {
    $line = fgets($stream);

    if ($line === false) {
        fclose($stream); // normal termination
        return;
    }

    echo $line;
});

$loop->run();
```

### `onWriteable(mixed $resource, Closure $closure): Watcher`

Calls the closure every time the resource becomes writable. A writable stream is usually ready
immediately, so close it as soon as the output is written.

```php
$stream = fopen(__DIR__ . '/output.txt', 'w');

$loop->onWriteable($stream, function ($stream) {
    fwrite($stream, "hello\n");
    fclose($stream); // normal termination
});

$loop->run();
```

Both methods return a `Watcher` handle. The loop keeps one read watcher and one write watcher per
resource, so a second watcher in the same direction on the same resource throws a `LogicException`.

### `readStream()` and `writeStream()`

The free functions `readStream()` and `writeStream()` provide the same behavior when called directly
inside a fiber. They return when the stream is closed or reaches EOF:

```php
use function bronsted\readStream;

$stream = fopen(__DIR__ . '/input.txt', 'r');

$loop->defer(function () use ($stream) {
    readStream($stream, function ($stream) {
        echo fread($stream, 8192);
    });

    fclose($stream);
});

$loop->run();
```

`writeStream()` works the same way for output:

```php
use function bronsted\writeStream;

$stream = fopen(__DIR__ . '/output.txt', 'w');

$loop->defer(function () use ($stream) {
    writeStream($stream, function ($stream) {
        fwrite($stream, "hello\n");
        fclose($stream);
    });
});

$loop->run();
```

### Resource lifetime

The loop holds a strong reference to the resource because `stream_select()` and the callback both
need it. The contract is:

| Action | Result |
| --- | --- |
| `fclose($stream)` | The watcher is pruned automatically and the fiber returns normally. |
| EOF on a read stream | `readStream()` returns normally after the read. |
| `$watcher->cancel()` | The fiber receives a `CancelledException` at its suspension point. |
| Fiber returns or throws | Its watchers and timers are cleaned up automatically. |
| `$loop->stop()` | All pending work is cancelled with `CancelledException`. |
| `unset($stream)` | No effect. The loop still holds the resource, so it is not closed. |

`fclose()` is the supported way to close a resource. Because the loop holds a strong reference,
dropping the last user variable with `unset()` does **not** close the resource and does **not**
remove the watcher. If the owner cannot call `fclose()`, use `cancel()` to unregister the watcher.

`fclose()` is normal termination, not cancellation. The waiting fiber resumes normally so `finally`
blocks still run, but no `CancelledException` is thrown.

## Watchers (advanced)

Most applications do not need to use the `Watcher` handle directly. Open the resource with `fopen()`,
close it with `fclose()`, and let the loop clean up the watcher.

The handle is useful when the application needs to control the watcher explicitly:

```php
$watcher = $loop->onReadable($stream, $closure);

$watcher->isActive();     // whether the watcher is still registered
$watcher->isReferenced(); // whether the watcher currently keeps the loop alive

$watcher->unref();        // keep the watcher active, but do not keep the loop alive
$watcher->ref();          // make the watcher keep the loop alive again

$watcher->cancel();       // unregister and cancel the waiting fiber
```

### Referenced and unreferenced watchers

A watcher is **referenced** by default. `run()` returns when there is no referenced work left. An
unreferenced watcher remains active and is still called while the loop is alive for other reasons,
but it does not keep the loop alive by itself.

This is mainly useful for long-lived listeners where some other work decides when the loop should
finish:

```php
$server = stream_socket_server('tcp://127.0.0.1:8080');

$listener = $loop->onReadable($server, function ($server) use ($loop) {
    $client = stream_socket_accept($server);
    $loop->onReadable($client, function ($client) {
        echo fread($client, 8192);
    });
});

$listener->unref(); // accepting connections should not keep the loop alive on its own
```

Without `unref()` the listener would keep `run()` alive forever. With `unref()` the loop can finish
when the rest of the program is done, while the listener still works as long as the loop is running.
Call `ref()` if the listener should keep the loop alive again.

### Cancelling

`cancel()` throws a `CancelledException` into the waiting fiber (when using `readStream()` /
`writeStream()`) so `finally` blocks run. It can be called from inside the watcher's own callback;
the cancellation takes effect at the next suspension point, so the current callback finishes first.

Cancelling a watcher whose resource has already been closed with `fclose()` is a no-op, and so is
cancelling an already cancelled or terminated watcher. Prefer `fclose()` for normal termination:
it resumes the fiber normally and does not throw `CancelledException`.

```php
use bronsted\CancelledException;

$loop->defer(function () use ($stream) {
    try {
        readStream($stream, function ($stream) {
            echo fread($stream, 8192);
        });
    } catch (CancelledException) {
        // The watcher was cancelled explicitly.
    } finally {
        // Always runs.
    }
});
```

## Suspend

`Suspend` lets one fiber wait until another fiber signals completion. `wait()` parks the fiber
without polling, and `done()` wakes it.

```php
use bronsted\FiberLoop;
use bronsted\Suspend;

$loop = FiberLoop::instance();

$loop->defer(function () use ($loop) {
    $suspend = new Suspend(5.0); // timeout in seconds

    $loop->onReadable($socket, function ($socket) use ($suspend) {
        fread($socket, 8192);
        fclose($socket);
        $suspend->done();
    });

    $suspend->wait();
    echo "done or timed out\n";
});
```

`wait()` throws an `Exception` if the timeout is reached before `done()` is called. Calling `done()`
before `wait()` makes the next `wait()` return immediately.

## Stopping the loop

`run()` returns when no referenced work remains. `stop()` stops the loop immediately and cancels all
pending fibers:

```php
$loop->defer(function () use ($loop) {
    $loop->stop();
});
```

A stopped loop can be run again. Work that is scheduled after `run()` has returned is processed by
the next `run()` call.

## Removed API

The following functions and methods were removed in favor of the event-driven implementation:

- `readStreamOnce()` and `writeStreamOnce()`
- `onReadableOnce()` and `onWriteableReady()`
- `sleep()`
- the `$timeout` argument of `onReadable()`

Use `onReadable()`/`onWriteable()` and cancel the returned watcher when you only need one event.
Use `delay()`/`repeat()` for time-based work.

## Tests

```bash
composer test
composer coverage
```

With Docker:

```bash
make image
make test
```

Containers started by the make targets are labelled `bronsted.fiberloop`. `make clean` only removes
exited or created containers with that label, so unrelated containers are left untouched.
