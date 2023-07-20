<?php

declare(strict_types=1);

namespace Loupe\Loupe\Logger;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

class InMemoryLogger implements LoggerInterface
{
    use LoggerTrait;

    private array $records = [];

    public function getRecords(): array
    {
        return $this->records;
    }

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];
    }
}
