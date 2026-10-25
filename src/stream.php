<?php
namespace bronsted;

use Closure;
use Fiber;

/**
 * Call the closure every time the stream becomes readable.
 *
 * The function returns when the stream is closed with fclose() or reaches EOF.
 * EOF is reported through one final call of the closure, so a reader waiting for
 * data can react to it instead of waiting for a timeout. The closure must handle
 * fread() returning an empty string.
 *
 * @param mixed $stream
 * @param Closure $closure
 * @param Watcher|null $watcher optional handle used by onReadable()
 * @return void
 * @throws \Throwable
 */
function readStream(mixed $stream, Closure $closure, ?Watcher $watcher = null): void
{
    while (is_resource($stream)) {
        if (feof($stream)) {
            $closure($stream);
            return;
        }

        Fiber::suspend(Wait::read($stream, $watcher));

        if (!is_resource($stream)) {
            return;
        }

        if (feof($stream)) {
            $closure($stream);
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
