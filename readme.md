# Fiberloop

This package implements [coroutines](https://en.wikipedia.org/wiki/Coroutine) by utilizing php 
[fibers](https://www.php.net/manual/en/language.fibers.php). It does the same job a [Revolt](https://revolt.run)
but implementation is different. It has the same interface as Revolt and I have expanded it a bit.

On top of this I have build [Psr-Http](https://github.com/sorenbronsted/psr-http) which is a PSR complaint
Http web server and a client.
 
Using coroutines enables you to write code which handles multiple tasks in php single threaded execution model.
This makes your program efficient and can handle more work.

## Installation
This package can be installed with [Composer](https://getcomposer.org/)

    `composer install bronsted\fiberloop`

## Usage

The loop waits for stream readiness or the next timer deadline when no fibers are runnable.
Waiting for one stream does not block other watchers or timers. Callbacks should still avoid
blocking I/O and other blocking operations.

The optional timeout on readable watchers is a maximum polling interval in microseconds,
not an inactivity timeout. A value of `0` waits for readiness; other scheduled work can
still wake the loop.

## Development

Containers started by the make targets have the `bronsted.fiberloop` label. `make clean`
only removes exited or created containers with that label; running containers and
unlabelled containers are left untouched.
