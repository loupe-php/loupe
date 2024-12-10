<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Unit\Internal;

use Loupe\Loupe\Internal\StaticCache;
use PHPUnit\Framework\TestCase;

class StaticCacheTest extends TestCase
{
    public function testCleanUpRemovesStaleReferences(): void
    {
        $object = new \stdClass();
        StaticCache::enterContext($object);

        StaticCache::set('key', 'value');
        $this->assertSame('value', StaticCache::get('key'));

        unset($object);

        StaticCache::cleanUp();

        $this->assertTrue(StaticCache::isEmpty());
    }

    public function testEnsureCurrentContextThrowsException(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Must enter static cache context first.');

        StaticCache::get('key');
    }

    public function testEnterContextAndSetAndGet(): void
    {
        $object = new \stdClass();
        StaticCache::enterContext($object);

        StaticCache::set('key', 'value');
        $this->assertSame('value', StaticCache::get('key'));
        unset($object);
        StaticCache::cleanUp();
    }

    public function testGetNonExistentKey(): void
    {
        $object = new \stdClass();
        StaticCache::enterContext($object);

        $this->assertNull(StaticCache::get('non_existent_key'));
        unset($object);
        StaticCache::cleanUp();
    }

    public function testHas(): void
    {
        $object = new \stdClass();
        StaticCache::enterContext($object);

        StaticCache::set('key', 'value');
        $this->assertTrue(StaticCache::has('key'));
        $this->assertFalse(StaticCache::has('missing_key'));
        unset($object);
        StaticCache::cleanUp();
    }

    public function testIsEmpty(): void
    {
        $object = new \stdClass();
        StaticCache::enterContext($object);

        $this->assertTrue(StaticCache::isEmpty());

        StaticCache::set('key', 'value');
        $this->assertFalse(StaticCache::isEmpty());
        unset($object);
        StaticCache::cleanUp();
    }
}
