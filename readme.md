# Fiberloop

This package/library implements [coroutines](https://en.wikipedia.org/wiki/Coroutine) by utilizing php 
[fibers](https://www.php.net/manual/en/language.fibers.php). It does the same job a [Revolt](https://revolt.run)
but implementation is different. It has the same interface as Revolt and I have expanded it a bit.

On top of this I have build [Psr-Http](https://github.com/sorenbronsted/psr-http) which is a psr complaint
implementation of ....

The following coroutines are available:
 - [defer]() a closure into the future
 - [delay]() a closure some time into the future
 - [repeat]() a closure every interval
 - call a closure for a given [signal]()
 - call a closure when data can be [read]() for a stream
 - call a closure when data can be [written]() for a stream
 - call a closure when data [ready to be read]() for a stream
 - call a closure when data [ready to be written]() for a stream

## Installation

    `composer install bronsted\fiberloop`

## Usage


