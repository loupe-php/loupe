<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\PhpUnit\Subscriber;

use Loupe\Loupe\Internal\StaticCache;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Framework\Assert;

class AssertStaticCacheIsEmptySubscriber implements FinishedSubscriber
{
    public function notify(Finished $event): void
    {
        Assert::assertTrue(StaticCache::isEmpty(), 'Static cache must be empty in: ' . $event->test()->id());
    }
}
