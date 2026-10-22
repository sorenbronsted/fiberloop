<?php
namespace  bronsted;

use Closure;
use Fiber;

/**
 * Call the closure very time the stream becomes readable
 * @param mixed $stream
 * @param Closure $closure
 * @param int $timeout
 * @return void
 * @throws \Throwable
 */
function readStream(mixed $stream, Closure $closure, int $timeout = 0)
{
    if ($timeout < 0) {
        throw new \ValueError('Timeout must be non-negative');
    }
    while(isStreamValid($stream)) {
        Fiber::suspend(new Wait(
            read: $stream,
            deadline: $timeout > 0 ? microtime(true) + $timeout / 1000000 : null
        ));
        if (!isStreamValid($stream)) {
            return;
        }
        $n = selectStream(read: $stream);
        if ($n > 0) {
            $closure($stream);
        }
    }
}

/**
 * Call the closure once when the stream becomes readable
 * @param mixed $stream
 * @param Closure $closure
 * @param int $timeout
 * @return void
 * @throws \Throwable
 */
function readStreamOnce(mixed $stream, Closure $closure, int $timeout = 0)
{
    if ($timeout < 0) {
        throw new \ValueError('Timeout must be non-negative');
    }
    while(isStreamValid($stream)) {
        Fiber::suspend(new Wait(
            read: $stream,
            deadline: $timeout > 0 ? microtime(true) + $timeout / 1000000 : null
        ));
        if (!isStreamValid($stream)) {
            return;
        }
        $n = selectStream(read: $stream);
        if ($n > 0) {
            $closure($stream);
            return;
        }
    }
}

/**
 * Call the closure every time the stream becomes writable
 * @param mixed $stream
 * @param Closure $closure
 * @return void
 * @throws \Throwable
 */
function writeStream(mixed $stream, Closure $closure)
{
    while(isStreamValid($stream, false)) {
        Fiber::suspend(new Wait(write: $stream));
        if (!isStreamValid($stream, false)) {
            return;
        }
        $n = selectStream(write: $stream);
        if ($n > 0) {
            $closure($stream);
        }
    }
}

/**
 * Call the closure once when stream becomes writeable
 * @param mixed $stream
 * @param Closure $closure
 * @return void
 * @throws \Throwable
 */
function writeStreamOnce(mixed $stream, Closure $closure)
{
    while(isStreamValid($stream, false)) {
        Fiber::suspend(new Wait(write: $stream));
        if (!isStreamValid($stream, false)) {
            return;
        }
        $n = selectStream(write: $stream);
        if ($n > 0) {
            $closure($stream);
            return;
        }
    }
}

/**
 * Check that a stream is open, optionally requiring that reading has not reached EOF
 * @param mixed $stream
 * @param bool $checkEof
 * @return bool
 */
function isStreamValid(mixed $stream, bool $checkEof = true): bool
{
    return is_resource($stream) && (!$checkEof || !feof($stream));
}

/**
 * Select from a stream if stream is ready for reading or writing
 * @param mixed $read
 * @param mixed $write
 * @param int $timeout
 * @return int
 */
function selectStream(mixed $read = null, mixed $write = null, int $timeout = 0): int
{
    $read = $read ? [$read] : null;
    $write = $write ? [$write] : null;
    $expect = null;
    return stream_select($read, $write, $expect, 0, $timeout);
}
