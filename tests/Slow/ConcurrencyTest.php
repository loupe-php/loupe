<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Slow;

use Loupe\Loupe\Configuration;
use Loupe\Loupe\Tests\Functional\FunctionalTestTrait;
use Loupe\Loupe\Tests\StorageFixturesTestTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

class ConcurrencyTest extends TestCase
{
    use FunctionalTestTrait;
    use StorageFixturesTestTrait;

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = $this->createTemporaryDirectory();
    }

    public function testLoupeDoesNotGetStuckIfProcessIsKilled(): void
    {
        $processes = [
            'worker-1' => $this->createWorkerProcess(500),
            'worker-2' => $this->createWorkerProcess(500),
            'worker-3' => $this->createWorkerProcess(0, [[
                'id' => 'the-one-in-question',
                'content' => 'Content of worker-3',
            ]]),
        ];

        // Run all processes, then kill worker-2 and assert that the stuff from worker-3 still make it into Loupe
        $this->runAndWaitForProcesses($processes, 'worker-2');

        $loupe = $this->createLoupe(self::getConfiguration(), $this->tempDir);

        $this->assertSame('Content of worker-3', $loupe->getDocument('the-one-in-question')['content'] ?? null);
    }

    public function testManyConcurrentProcesses(): void
    {
        $processes = [];

        // Create 5 processes with 100 random documents which are all bigger in content so that indexing one document
        // takes longer, showcasing that simply indexing one document after the other within its own transaction
        // is not sufficient if there are too many concurrent processes.
        for ($i = 1; $i <= 5; $i++) {
            $processes['worker-' . $i] = $this->createWorkerProcess(100, [], [], 1000);
        }

        $this->runAndWaitForProcesses($processes);

        $loupe = $this->createLoupe(self::getConfiguration(), $this->tempDir);

        $this->assertSame(500, $loupe->countDocuments());
    }

    public function testTheLatestProcessWins(): void
    {
        $processes = [
            // Worker 1 creates 500 random documents
            'worker-1' => $this->createWorkerProcess(500),
            // Worker 2 creates one specific document
            'worker-2' => $this->createWorkerProcess(0, [[
                'id' => 'the-one-in-question',
                'content' => 'Content of worker-2',
            ]]),
            // Worker 3 overrides that specific document but first, it creates 1000 other documents so overriding happens last
            'worker-3' => $this->createWorkerProcess(1000, [], [[
                'id' => 'the-one-in-question',
                'content' => 'Content of worker-3',
            ]]),
            // Worker 4 also wants to override that document, but it does it at the beginning
            'worker-4' => $this->createWorkerProcess(0, [[
                'id' => 'the-one-in-question',
                'content' => 'Content of worker-4',
            ]]),
        ];

        $this->runAndWaitForProcesses($processes);

        $loupe = $this->createLoupe(self::getConfiguration(), $this->tempDir);

        // Should have 1501 documents because 1500 random plus "the-one-in-question"
        $this->assertSame(1501, $loupe->countDocuments());

        // The last worker that was started is worker-4 so even though previous processes might take longer to complete,
        // the last one that wanted to modify a document, must be the one that wins
        $this->assertSame('Content of worker-4', $loupe->getDocument('the-one-in-question')['content'] ?? null);
    }

    /**
     * @param array<array<string, mixed>> $preDocuments
     * @param array<array<string, mixed>> $postDocuments
     */
    private function createWorkerProcess(int $numberOfRandomDocuments, array $preDocuments = [], array $postDocuments = [], int $numberOfWordsPerDocument = 100): Process
    {
        $command = [(new PhpExecutableFinder())->find(), __DIR__ . '/../bin/worker.php'];
        $env = [
            'LOUPE_FUNCTIONAL_TEST_TEMP_DIR' => $this->tempDir,
            'LOUPE_FUNCTIONAL_TEST_CONFIGURATION' => self::getConfiguration()->toString(),
            'LOUPE_FUNCTIONAL_TEST_NUMBER_OF_RANDOM_DOCUMENTS' => $numberOfRandomDocuments,
            'LOUPE_FUNCTIONAL_TEST_PRE_DOCUMENTS' => json_encode($preDocuments),
            'LOUPE_FUNCTIONAL_TEST_POST_DOCUMENTS' => json_encode($postDocuments),
            'LOUPE_FUNCTIONAL_TEST_NUMBER_OF_WORDS_PER_DOCUMENT' => $numberOfWordsPerDocument,
        ];

        return new Process($command, env: $env, timeout: null);
    }

    private static function getConfiguration(): Configuration
    {
        return Configuration::create()
            ->withSearchableAttributes(['content'])
            ->withLanguages(['en'])
        ;
    }

    /**
     * @param array<string, Process> $processes
     */
    private function runAndWaitForProcesses(array $processes, string|null $processToKill = null): void
    {
        foreach ($processes as $process) {
            usleep(500000); // 0.5 seconds to simulate incoming workers one after the other
            $process->start();
        }

        if ($processToKill !== null) {
            $processes[$processToKill]->stop(0);
        }

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
}
