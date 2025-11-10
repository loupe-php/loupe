<?php

declare(strict_types=1);

use Loupe\Loupe\Configuration;
use Loupe\Loupe\LoupeFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Psr\Log\LogLevel;

require __DIR__ . '/../../vendor/autoload.php';

$env = getenv();
$configuration = $env['LOUPE_FUNCTIONAL_TEST_CONFIGURATION'] ?? null;
$tempDir = $env['LOUPE_FUNCTIONAL_TEST_TEMP_DIR'] ?? null;
$numberOfRandomDocuments = (int) ($env['LOUPE_FUNCTIONAL_TEST_NUMBER_OF_RANDOM_DOCUMENTS'] ?? 0);
$numberOfWordsPerDocument = (int) ($env['LOUPE_FUNCTIONAL_TEST_NUMBER_OF_WORDS_PER_DOCUMENT'] ?? 100);
$preDocuments = json_decode($env['LOUPE_FUNCTIONAL_TEST_PRE_DOCUMENTS'] ?? '{}', true);
$postDocuments = json_decode($env['LOUPE_FUNCTIONAL_TEST_POST_DOCUMENTS'] ?? '{}', true);
$outputWorkerLogFile = $env['LOUPE_OUTPUT_WORKER_LOG'] ?? null;

$generateRandomWords = function () use ($numberOfWordsPerDocument): string {
    static $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $words = [];

    for ($i = 0; $i < $numberOfWordsPerDocument; $i++) {
        $length = rand(3, 10); // each word will be between 3–10 chars
        $word = '';

        for ($j = 0; $j < $length; $j++) {
            $word .= $alphabet[rand(0, \strlen($alphabet) - 1)];
        }

        $words[] = $word;
    }

    return implode(' ', $words);
};

$generateDocuments = function (int $count) use ($generateRandomWords): array {
    $documents = [];

    for ($i = 0; $i < $count; $i++) {
        $documents[] = [
            'id' => uniqid(),
            'content' => $generateRandomWords(),
        ];
    }

    return $documents;
};

if (!$configuration || !$tempDir) {
    echo 'Did not pass the expected environment variables for this test.';
    exit(1);
}

try {
    $configuration = Configuration::fromString($configuration);

    if ($outputWorkerLogFile) {
        $configuration = $configuration->withLogger(new class($outputWorkerLogFile) implements LoggerInterface {
            use LoggerTrait;

            public function __construct(
                private string $outputWorkerLogFile
            ) {

            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                if ($level !== LogLevel::INFO) {
                    return;
                }

                file_put_contents($this->outputWorkerLogFile, $message . PHP_EOL, FILE_APPEND);
            }
        });
    }

} catch (\Throwable $exception) {
    echo 'Could not instantiate the configuration for this test: ' . $exception->getMessage();
    exit(1);
}

try {
    $loupeFactory = new LoupeFactory();
    $loupe = $loupeFactory->create($tempDir, $configuration);
} catch (\Throwable $exception) {
    echo 'Could not instantiate the loupe factory for this test: ' . $exception->getMessage();
    exit(1);
}

try {
    $documents = array_merge(
        $preDocuments,
        $generateDocuments($numberOfRandomDocuments),
        $postDocuments
    );

    $loupe->addDocuments($documents);
} catch (\Throwable $exception) {
    echo 'Could not add documents for this test: ' . $exception->getMessage();
    exit(1);
}

exit(0);
