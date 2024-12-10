<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\PhpUnit\Extension;

use Loupe\Loupe\Tests\PhpUnit\Subscriber\AssertStaticCacheIsEmptySubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

class LoupeTestsExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new AssertStaticCacheIsEmptySubscriber());
    }
}
