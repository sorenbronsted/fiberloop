<?php

namespace bronsted\test {
    final class StreamSelectStub
    {
        public static int $failures = 0;
        public static int $calls = 0;
    }
}

namespace bronsted {
    function stream_select(?array &$read, ?array &$write, ?array &$except, ?int $seconds, ?int $microseconds = null): int|false
    {
        \bronsted\test\StreamSelectStub::$calls += 1;
        if (\bronsted\test\StreamSelectStub::$failures > 0) {
            \bronsted\test\StreamSelectStub::$failures -= 1;
            return false;
        }
        return \stream_select($read, $write, $except, $seconds, $microseconds);
    }
}
