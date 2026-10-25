<?php
namespace bronsted;

use Closure;
use Fiber;

/**
 * Call the closure every time the stream becomes readable.
 *
 * The function returns when the stream is closed with fclose() or reaches EOF.
 *
 * @param mixed $stream
 * @param Closure $closure
 * @param Watcher|null $watcher optional handle used by onReadable()
 * @return void
 * @throws \Throwable
 */
function readStream(mixed $stream, Closure $closure, ?Watcher $watcher = null): void
{
    while (isStreamValid($stream)) {
        Fiber::suspend(Wait::read($stream, $watcher));

        if (!isStreamValid($stream)) {
            return;
        }

        $closure($stream);
    }
}

/**
 * Call the closure every time the stream becomes writable.
 *
 * The function returns when the stream is closed with fclose() or reaches EOF.
 *
 * @param mixed $stream
 * @param Closure $closure
 * @param Watcher|null $watcher optional handle used by onWriteable()
 * @return void
 * @throws \Throwable
 */
function writeStream(mixed $stream, Closure $closure, ?Watcher $watcher = null): void
{
    while (isStreamValid($stream, false)) {
        Fiber::suspend(Wait::write($stream, $watcher));

        if (!isStreamValid($stream, false)) {
            return;
        }

        $closure($stream);
    }
}

/**
 * Check that a stream is valid and not closed.
 *
 * Reading stops at EOF, but a writable socket can still accept data after its
 * peer has half-closed the connection, so write paths must not check feof().
 *
 * @param mixed $stream
 * @param bool $checkEof
 * @return bool
 */
function isStreamValid(mixed $stream, bool $checkEof = true): bool
{
    return is_resource($stream) && (!$checkEof || !feof($stream));
}
