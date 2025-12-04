<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Psr\Log\LogLevel;

class WorkerLogger implements LoggerInterface
{
    use LoggerTrait;

    private ?string $logFile;

    public function __construct(
        private string $workerName,
        ?string $logFile = null
    ) {
        $this->logFile = $logFile ?? getenv('LOUPE_OUTPUT_WORKER_LOG') ?: null;
    }

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        if ($this->logFile === null) {
            return;
        }

        if ($level !== LogLevel::INFO) {
            return;
        }

        $message = \sprintf('[%s] %s', $this->workerName, $message);

        file_put_contents($this->logFile, $message . PHP_EOL, FILE_APPEND);
    }
}
