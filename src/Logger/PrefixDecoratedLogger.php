<?php

declare(strict_types=1);

namespace Loupe\Loupe\Logger;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

class PrefixDecoratedLogger implements LoggerInterface
{
    use LoggerTrait;

    public function __construct(
        private string $prefix,
        private LoggerInterface $innerLogger
    ) {

    }

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $message = \sprintf('[%s]: %s', $this->prefix, $message);
        $this->innerLogger->log($level, $message, $context);
    }
}
