<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests;

class Util
{
    public static function fixturesPath(?string $path = null): string
    {
        return __DIR__ . '/Fixtures' . ($path ? '/' . $path : '');
    }
}
