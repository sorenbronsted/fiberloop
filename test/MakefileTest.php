<?php

use PHPUnit\Framework\TestCase;

class MakefileTest extends TestCase
{
    private ?string $directory = null;
    private string $make;

    protected function setUp(): void
    {
        parent::setUp();
        if (PHP_OS_FAMILY === 'Windows' || !function_exists('proc_open')) {
            $this->markTestSkipped('Makefile tests require a POSIX shell and proc_open');
        }
        $this->make = $this->findMake();
        $directory = tempnam(sys_get_temp_dir(), 'fiberloop-make-');
        $this->assertNotFalse($directory);
        $this->directory = $directory;
        unlink($directory);
        mkdir($directory, 0700);
        mkdir($directory . '/bin', 0700);
        // A real directory named test must not prevent the phony test target.
        mkdir($directory . '/test', 0700);
        copy(__DIR__ . '/fixtures/docker', $directory . '/bin/docker');
        chmod($directory . '/bin/docker', 0700);
        $this->assertTrue(is_executable($directory . '/bin/docker'));
        file_put_contents($directory . '/docker.log', '');
    }

    protected function tearDown(): void
    {
        if ($this->directory !== null) {
            foreach (['bin/docker', 'docker.log'] as $file) {
                $path = $this->directory . '/' . $file;
                if (is_file($path)) {
                    unlink($path);
                }
            }
            foreach (['bin', 'test', ''] as $directory) {
                $path = $this->directory . '/' . $directory;
                if (is_dir($path)) {
                    rmdir($path);
                }
            }
        }
        parent::tearDown();
    }

    public function testCleanOnlyRemovesStoppedProjectContainers()
    {
        [$status, $calls, $output] = $this->runMake('clean', [
            'DOCKER_CONTAINERS' => implode("\n", [
                'foreign-exited|another.project|exited',
                'project-running|bronsted.fiberloop|running',
                'project-exited|bronsted.fiberloop|exited',
                'project-created|bronsted.fiberloop|created',
                'foreign-created|another.project|created',
                'unlabelled||exited',
            ]),
        ]);

        $this->assertSame(0, $status, $output);
        $this->assertCount(2, $calls);
        $this->assertStringContainsString('--filter label=bronsted.fiberloop', $calls[0]);
        $this->assertSame('rm project-exited project-created', $calls[1]);
    }

    public function testCleanWithNoMatchingContainersDoesNotRemoveAnything()
    {
        [$status, $calls, $output] = $this->runMake('clean', [
            'DOCKER_CONTAINERS' => "foreign|another.project|exited\nrunning|bronsted.fiberloop|running",
        ]);

        $this->assertSame(0, $status, $output);
        $this->assertCount(1, $calls);
        $this->assertStringStartsWith('ps ', $calls[0]);
    }

    public function testCleanDoesNotRemoveAnythingIfListingFails()
    {
        [$status, $calls, $output] = $this->runMake('clean', ['DOCKER_PS_FAIL' => '1']);

        $this->assertNotSame(0, $status);
        $this->assertCount(1, $calls);
        $this->assertStringStartsWith('ps ', $calls[0]);
        $this->assertStringContainsString('mock list failure', $output);
    }

    public function testCleanPropagatesRemovalFailure()
    {
        [$status, $calls, $output] = $this->runMake('clean', [
            'DOCKER_CONTAINERS' => 'project-exited|bronsted.fiberloop|exited',
            'DOCKER_RM_FAIL' => '1',
        ]);

        $this->assertNotSame(0, $status);
        $this->assertSame('rm project-exited', $calls[1]);
        $this->assertStringContainsString('mock remove failure', $output);
    }

    /**
     * @dataProvider containerTargets
     */
    public function testContainerTargetsApplyProjectLabel(string $target, string $suffix)
    {
        [$status, $calls, $output] = $this->runMake($target);

        $this->assertSame(0, $status, $output);
        $this->assertCount(1, $calls);
        $this->assertStringStartsWith('run ', $calls[0]);
        $this->assertStringContainsString('--label bronsted.fiberloop', $calls[0]);
        $this->assertStringEndsWith($suffix, $calls[0]);
    }

    public static function containerTargets(): array
    {
        return [
            'bash' => ['bash', 'fiberloop bash'],
            'test' => ['test', 'composer run test'],
            'composer' => ['composer', 'composer install'],
        ];
    }

    private function findMake(): string
    {
        foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $directory) {
            $path = $directory . '/make';
            if (is_file($path) && is_executable($path)) {
                return realpath($path);
            }
        }
        $this->markTestSkipped('Makefile tests require make');
    }

    private function runMake(string $target, array $overrides = []): array
    {
        $environment = getenv();
        foreach (['MAKEFLAGS', 'MFLAGS', 'GNUMAKEFLAGS', 'MAKEOVERRIDES', 'MAKELEVEL'] as $variable) {
            unset($environment[$variable]);
        }
        $environment = array_merge($environment, [
            // No fallback to the real Docker binary, even if the mock is missing.
            'PATH' => $this->directory . '/bin',
            'DOCKER_LOG' => $this->directory . '/docker.log',
            'DOCKER_CONTAINERS' => '',
            'DOCKER_PS_FAIL' => '0',
            'DOCKER_RM_FAIL' => '0',
        ], $overrides);
        $process = proc_open([
            $this->make,
            '--no-print-directory',
            '-f', dirname(__DIR__) . '/makefile',
            $target,
            'CMD=install',
        ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->directory, $environment);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        $log = trim(file_get_contents($this->directory . '/docker.log'));

        return [$status, $log === '' ? [] : explode("\n", $log), $output];
    }
}
