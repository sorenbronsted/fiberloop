<?php

use bronsted\test\Clock;
use bronsted\Wait;
use PHPUnit\Framework\TestCase;

/**
 * Keep the fake clock out of other tests and the production scheduler.
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class TimerEdgeTest extends TestCase
{
    public function testDelayRemainsSuspendedWhenResumedBeforeDeadline()
    {
        require __DIR__ . '/fixtures/clock.php';
        $called = 0;
        $fiber = new Fiber(function() use (&$called) {
            bronsted\delay(5, function() use (&$called) {
                $called += 1;
            });
        });

        $wait = $fiber->start();
        $this->assertInstanceOf(Wait::class, $wait);
        $this->assertSame(105.0, $wait->deadline);
        Clock::$now = 102;
        $wait = $fiber->resume();
        $this->assertSame(105.0, $wait->deadline);
        $this->assertSame(0, $called);
        $this->assertTrue($fiber->isSuspended());

        Clock::$now = 105;
        $fiber->resume();
        $this->assertSame(1, $called);
        $this->assertTrue($fiber->isTerminated());
    }

    public function testZeroDelayStillDefersCallback()
    {
        require __DIR__ . '/fixtures/clock.php';
        $called = false;
        $fiber = new Fiber(function() use (&$called) {
            bronsted\delay(0, function() use (&$called) {
                $called = true;
            });
        });

        $wait = $fiber->start();
        $this->assertSame(100.0, $wait->deadline);
        $this->assertFalse($called);
        $fiber->resume();
        $this->assertTrue($called);
        $this->assertTrue($fiber->isTerminated());
    }

    public function testRepeatCanChangeIntervalAndStop()
    {
        require __DIR__ . '/fixtures/clock.php';
        $intervals = [];
        $fiber = new Fiber(function() use (&$intervals) {
            bronsted\repeat(5, function(float $interval) use (&$intervals) {
                $intervals[] = $interval;
                return count($intervals) == 1 ? 2.0 : 0.0;
            });
        });

        $this->assertSame(105.0, $fiber->start()->deadline);
        Clock::$now = 105;
        $this->assertSame(107.0, $fiber->resume()->deadline);
        $this->assertSame([5.0], $intervals);

        Clock::$now = 106;
        $this->assertSame(107.0, $fiber->resume()->deadline);
        $this->assertSame([5.0], $intervals);
        Clock::$now = 107;
        $fiber->resume();
        $this->assertSame([5.0, 2.0], $intervals);
        $this->assertTrue($fiber->isTerminated());
    }

    public function testNullReturnKeepsRepeatInterval()
    {
        require __DIR__ . '/fixtures/clock.php';
        $intervals = [];
        $fiber = new Fiber(function() use (&$intervals) {
            bronsted\repeat(2, function(float $interval) use (&$intervals) {
                $intervals[] = $interval;
                return count($intervals) == 1 ? null : 0;
            });
        });

        $this->assertSame(102.0, $fiber->start()->deadline);
        Clock::$now = 102;
        $this->assertSame(104.0, $fiber->resume()->deadline);
        Clock::$now = 104;
        $fiber->resume();

        $this->assertSame([2.0, 2.0], $intervals);
        $this->assertTrue($fiber->isTerminated());
    }

    public function testZeroRepeatIntervalRunsOnceAndStops()
    {
        require __DIR__ . '/fixtures/clock.php';
        $called = 0;
        $fiber = new Fiber(function() use (&$called) {
            bronsted\repeat(0, function() use (&$called) {
                $called += 1;
            });
        });

        $fiber->start();
        $this->assertSame(0, $called);
        $fiber->resume();
        $this->assertSame(1, $called);
        $this->assertTrue($fiber->isTerminated());
    }

    public function testSleepRejectsNegativeMicroseconds()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('microseconds must be positive');
        bronsted\sleep(-1, function() {});
    }

    /**
     * @dataProvider sleepIntervals
     */
    public function testSleepSuspendsAndSleepsOnEveryResume(int $microseconds)
    {
        $fiber = new Fiber(fn() => bronsted\sleep($microseconds, function() {}));
        $this->assertNull($fiber->start());
        $this->assertTrue($fiber->isSuspended());

        for ($i = 0; $i < 2; $i++) {
            $start = hrtime(true);
            $this->assertNull($fiber->resume());
            $elapsed = (hrtime(true) - $start) / 1000;
            $this->assertGreaterThanOrEqual($microseconds, $elapsed);
            $this->assertTrue($fiber->isSuspended());
        }
    }

    public static function sleepIntervals(): array
    {
        return [
            'zero' => [0],
            'positive' => [1000],
        ];
    }
}
