<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Slow;

use Loupe\Loupe\Configuration;
use Loupe\Loupe\Tests\Functional\FunctionalTestTrait;
use Loupe\Loupe\Tests\StorageFixturesTestTrait;
use Loupe\Loupe\Tests\WorkerLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

final class ConcurrencyTest extends TestCase
{
    use FunctionalTestTrait;
    use StorageFixturesTestTrait;

    private const WORKER_LOCK_TIMEOUT_SECONDS = 60;

    private string $tempDir;

    private string $workerLogFile;

    protected function setUp(): void
    {
        $this->tempDir = $this->createTemporaryDirectory();
        $this->workerLogFile = $this->tempDir . '/workers.log';
    }

    public function testLoupeDoesNotGetStuckIfProcessIsKilled(): void
    {
        $processes = [
            'worker-1' => $this->createWorkerProcess('worker-1', [
                'numberOfRandomDocuments' => 500,
            ]),
            'worker-2' => $this->createWorkerProcess('worker-2', [
                'numberOfRandomDocuments' => 500,
            ]),
            'worker-3' => $this->createWorkerProcess('worker-3', [
                'preDocuments' => [[
                    'id' => 'the-one-in-question',
                    'content' => 'Content of worker-3',
                ]],
            ]),
        ];

        // Run all processes, then kill worker-2 and assert that the stuff from worker-3 still make it into Loupe
        $this->runAndWaitForProcesses($processes, 'worker-2');

        $loupe = $this->createLoupe($this->getConfiguration('parent'), $this->tempDir);

        $this->assertSame('Content of worker-3', $loupe->getDocument('the-one-in-question')['content'] ?? null);
    }

    public function testManyConcurrentProcesses(): void
    {
        $processes = [];

        // Create 5 processes with 100 random documents which are all bigger in content so that indexing one document
        // takes longer, showcasing that simply indexing one document after the other within its own transaction
        // is not sufficient if there are too many concurrent processes.
        for ($i = 1; $i <= 5; ++$i) {
            $processes['worker-' . $i] = $this->createWorkerProcess('worker-' . $i, [
                'numberOfRandomDocuments' => 100,
                'numberOfWordsPerDocument' => 1000,
            ]);
        }

        $this->runAndWaitForProcesses($processes);

        $loupe = $this->createLoupe($this->getConfiguration('parent'), $this->tempDir);

        $this->assertSame(500, $loupe->countDocuments());
    }

    public function testTheLatestProcessWins(): void
    {
        $processes = [
            // Worker 1 creates 500 random documents
            'worker-1' => $this->createWorkerProcess('worker-1', [
                'numberOfRandomDocuments' => 500,
            ]),
            // Worker 2 creates one specific document
            'worker-2' => $this->createWorkerProcess('worker-2', [
                'preDocuments' => [[
                    'id' => 'the-one-in-question',
                    'content' => 'Content of worker-2',
                ]],
            ]),
            // Worker 3 overrides that specific document but first, it creates 1000 other documents so overriding happens last
            'worker-3' => $this->createWorkerProcess('worker-3', [
                'numberOfRandomDocuments' => 1000,
                'postDocuments' => [[
                    'id' => 'the-one-in-question',
                    'content' => 'Content of worker-3',
                ]],
            ]),
            // Worker 4 also wants to override that document, but it does it at the beginning
            'worker-4' => $this->createWorkerProcess('worker-4', [
                'preDocuments' => [[
                    'id' => 'the-one-in-question',
                    'content' => 'Content of worker-4',
                ]],
            ]),
        ];

        foreach (['worker-1', 'worker-2', 'worker-3'] as $workerName) {
            $this->startWorker($workerName, $processes[$workerName]);
        }

        $this->waitForWorkerToAcquireLock('worker-3', $processes['worker-3']);
        $this->startWorker('worker-4', $processes['worker-4']);
        $this->waitForProcesses($processes);

        $loupe = $this->createLoupe($this->getConfiguration('parent'), $this->tempDir);

        // Should have 1501 documents because 1500 random plus "the-one-in-question"
        $this->assertSame(1501, $loupe->countDocuments());

        // The last worker that was started is worker-4 so even though previous processes might take longer to complete,
        // the last one that wanted to modify a document, must be the one that wins
        $this->assertSame('Content of worker-4', $loupe->getDocument('the-one-in-question')['content'] ?? null);
    }

    /**
     * @param array{
     *     numberOfRandomDocuments?: int,
     *     numberOfWordsPerDocument?: int,
     *     postDocuments?: array<array<string, mixed>>,
     *     preDocuments?: array<array<string, mixed>>
     * } $options
     */
    private function createWorkerProcess(string $workerName, array $options = []): Process
    {
        $command = [(new PhpExecutableFinder())->find(), __DIR__ . '/../bin/worker.php'];
        $env = [
            'LOUPE_FUNCTIONAL_TEST_TEMP_DIR' => $this->tempDir,
            'LOUPE_FUNCTIONAL_TEST_CONFIGURATION' => $this->getConfiguration($this->prefixWorkerNameWithTest($workerName))->toString(),
            'LOUPE_FUNCTIONAL_TEST_NUMBER_OF_RANDOM_DOCUMENTS' => $options['numberOfRandomDocuments'] ?? 0,
            'LOUPE_FUNCTIONAL_TEST_PRE_DOCUMENTS' => json_encode($options['preDocuments'] ?? []),
            'LOUPE_FUNCTIONAL_TEST_POST_DOCUMENTS' => json_encode($options['postDocuments'] ?? []),
            'LOUPE_FUNCTIONAL_TEST_NUMBER_OF_WORDS_PER_DOCUMENT' => $options['numberOfWordsPerDocument'] ?? 100,
            'LOUPE_OUTPUT_WORKER_LOG' => $this->workerLogFile,
        ];

        return new Process($command, env: $env, timeout: null);
    }

    private function getConfiguration(string $processName): Configuration
    {
        return Configuration::create()
            ->withSearchableAttributes(['content'])
            ->withLanguages(['en'])
            ->withProcessName($processName)
            ->withLogger($this->getWorkerLogger($processName))
        ;
    }

    private function getWorkerLogger(string $workerName): WorkerLogger
    {
        return new WorkerLogger($this->prefixWorkerNameWithTest($workerName), $this->workerLogFile);
    }

    private function prefixWorkerNameWithTest(string $workerName): string
    {
        return \sprintf('%s %s', $this->toString(), $workerName);
    }

    /**
     * @param array<string, Process> $processes
     */
    private function runAndWaitForProcesses(array $processes, string|null $processToKill = null): void
    {
        foreach ($processes as $processName => $process) {
            $this->startWorker($processName, $process);
        }

        if ($processToKill !== null) {
            $processes[$processToKill]->stop(0);
        }

        $this->waitForProcesses($processes, $processToKill);
    }

    private function startWorker(string $workerName, Process $process): void
    {
        usleep(500000); // 0.5 seconds to simulate incoming workers one after the other
        $this->getWorkerLogger($workerName)->log(LogLevel::INFO, 'Starting worker ' . $workerName);
        $process->start();
    }

    /**
     * @param array<string, Process> $processes
     */
    private function waitForProcesses(array $processes, string|null $processToKill = null): void
    {
        // Wait for them to complete
        $errors = [];

        foreach ($processes as $processName => $process) {
            $process->wait();

            if ($processToKill !== null && $processToKill === $processName) {
                continue;
            }

            if (!$process->isSuccessful()) {
                $errors[] = \sprintf('[%s]: ', $processName) . $process->getOutput() . PHP_EOL . $process->getErrorOutput();
            }
        }

        if ($errors !== []) {
            $this->fail(implode(PHP_EOL, $errors));
        }
    }

    private function waitForWorkerToAcquireLock(string $workerName, Process $process): void
    {
        $processName = $this->prefixWorkerNameWithTest($workerName);
        $expectedLogMessage = \sprintf('[%s] [%s]: [TicketHandler', $processName, $processName);
        $deadline = microtime(true) + self::WORKER_LOCK_TIMEOUT_SECONDS;

        while (microtime(true) < $deadline) {
            if ($this->workerHasAcquiredLock($expectedLogMessage)) {
                return;
            }

            if (!$process->isRunning()) {
                $this->fail(\sprintf(
                    'Worker %s exited with code %s before acquiring the writer lock.%sOutput: %s%sError output: %s',
                    $workerName,
                    $process->getExitCode() ?? 'unknown',
                    PHP_EOL,
                    $process->getOutput(),
                    PHP_EOL,
                    $process->getErrorOutput(),
                ));
            }

            usleep(10_000);
        }

        $this->fail(\sprintf(
            'Worker %s did not acquire the writer lock within %d seconds.',
            $workerName,
            self::WORKER_LOCK_TIMEOUT_SECONDS,
        ));
    }

    private function workerHasAcquiredLock(string $expectedLogMessage): bool
    {
        if (!is_file($this->workerLogFile)) {
            return false;
        }

        $logContents = file_get_contents($this->workerLogFile);
        if ($logContents === false) {
            return false;
        }

        foreach (explode(PHP_EOL, $logContents) as $line) {
            if (str_contains($line, $expectedLogMessage) && str_ends_with($line, 'Writer lock acquired')) {
                return true;
            }
        }

        return false;
    }
}
