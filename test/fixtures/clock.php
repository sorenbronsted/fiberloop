<?php

namespace bronsted\test {
    final class Clock
    {
        public static float $now = 100.0;
    }
}

namespace bronsted {
    function microtime(bool $asFloat = false): string|float
    {
        return $asFloat ? \bronsted\test\Clock::$now : '0.00000000 ' . (int) \bronsted\test\Clock::$now;
    }
}
