<?php

declare(strict_types=1);

namespace Loupe\Loupe\Logger;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

class InMemoryLogger implements LoggerInterface
{
    use LoggerTrait;

    /**
     * @var array<array{level:string, message: string|\Stringable, context: array<mixed>}>
     */
    private array $records = [];

    /**
     * @return array<array{level:string, message: string|\Stringable, context: array<mixed>}>
     */
    public function getRecords(): array
    {
        return $this->records;
    }

    /**
     * @param string $level
     */
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];
    }
}
