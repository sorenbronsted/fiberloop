<?php

namespace bronsted;

/**
 * A fiber suspends with a Wait to tell the loop what it is waiting for.
 *
 * @internal
 */
final class Wait
{
    private function __construct(
        public readonly WaitKind $kind,
        public readonly ?float $deadline = null,
        public readonly mixed $resource = null,
        public readonly ?Watcher $watcher = null,
    ) {
    }

    public static function ready(): self
    {
        return new self(WaitKind::Ready);
    }

    public static function timer(float $deadline): self
    {
        return new self(WaitKind::Timer, $deadline);
    }

    public static function read(mixed $resource, ?Watcher $watcher = null): self
    {
        return new self(WaitKind::Read, null, $resource, $watcher);
    }

    public static function write(mixed $resource, ?Watcher $watcher = null): self
    {
        return new self(WaitKind::Write, null, $resource, $watcher);
    }

    public static function park(?float $deadline = null): self
    {
        return new self(WaitKind::Park, $deadline);
    }
}
