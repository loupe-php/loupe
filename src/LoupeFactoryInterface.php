<?php

declare(strict_types=1);

namespace Loupe\Loupe;

interface LoupeFactoryInterface
{
    public function create(string $dataDir, Configuration $configuration): Loupe;

    public function createInMemory(Configuration $configuration): Loupe;

    public function isSupported(): bool;
}
