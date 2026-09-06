<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Benchmark;

use Loupe\Loupe\BrowseParameters;
use Loupe\Loupe\Loupe;
use PhpBench\Attributes\BeforeClassMethods;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Groups;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\OutputTimeUnit;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

#[BeforeClassMethods('setUpClass')]
#[BeforeMethods('setUp')]
#[Revs(1)]
#[Iterations(5)]
#[Warmup(2)]
#[OutputTimeUnit('milliseconds', precision: 2)]
#[Groups(['browse'])]
class BrowseBench extends AbstractBench
{
    private Loupe $loupe;

    public function setUp(): void
    {
        $this->loupe = self::loupe(self::searchIndexPath());
    }

    public function benchAllPrimaryKeys(): void
    {
        $documentCount = $this->loupe->countDocuments();

        for ($offset = 0; $offset < $documentCount; $offset += 1_000) {
            $this->loupe->browse(
                BrowseParameters::create()
                    ->withAttributesToRetrieve(['id'])
                    ->withLimit(1_000)
                    ->withOffset($offset),
            );
        }
    }

    public static function setUpClass(): void
    {
        self::ensureSearchIndex();
    }
}
