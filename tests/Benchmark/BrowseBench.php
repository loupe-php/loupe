<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Benchmark;

use Loupe\Loupe\BrowseParameters;
use Loupe\Loupe\Configuration;
use Loupe\Loupe\Indexing\DocumentSource;
use Loupe\Loupe\Loupe;
use Loupe\Loupe\LoupeFactory;
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
#[Iterations(10)]
#[Warmup(2)]
#[OutputTimeUnit('milliseconds', precision: 2)]
#[Groups(['browse'])]
class BrowseBench extends AbstractBench
{
    private const ARTICLE_COUNT = 20_000;

    private const PAGE_SIZE = 1_000;

    private Loupe $articles;

    private int $articlesCount = 0;

    private int $documentCount = 0;

    private Loupe $loupe;

    public function setUp(): void
    {
        $this->loupe = self::loupe(self::searchIndexPath());
        $this->documentCount = $this->loupe->countDocuments();

        $this->articles = self::articlesLoupe();
        $this->articlesCount = $this->articles->countDocuments();
    }

    public function benchBrowseLargeSubset(): void
    {
        $this->browseAll($this->articles, $this->articlesCount, ['id', 'title']);
    }

    public function benchBrowseLargeWholeDocument(): void
    {
        $this->browseAll($this->articles, $this->articlesCount, ['*']);
    }

    public function benchBrowsePrimaryKey(): void
    {
        $this->browseAll($this->loupe, $this->documentCount, ['id']);
    }

    public function benchBrowseSubset(): void
    {
        $this->browseAll($this->loupe, $this->documentCount, ['id', 'title', 'release_date']);
    }

    public function benchBrowseSubsetFiltered(): void
    {
        for ($offset = 0; $offset < $this->documentCount; $offset += self::PAGE_SIZE) {
            $this->loupe->browse(
                BrowseParameters::create()
                    ->withAttributesToRetrieve(['id', 'title'])
                    ->withFilter('release_date > 0')
                    ->withLimit(self::PAGE_SIZE)
                    ->withOffset($offset),
            );
        }
    }

    public function benchBrowseWholeDocument(): void
    {
        $this->browseAll($this->loupe, $this->documentCount, ['*']);
    }

    public static function setUpClass(): void
    {
        self::ensureSearchIndex();
        self::ensureArticleIndex();
    }

    private static function articleDocuments(): \Generator
    {
        $lorem = 'lorem ipsum dolor sit amet consectetur adipiscing elit sed do eiusmod tempor ';

        for ($i = 1; $i <= self::ARTICLE_COUNT; ++$i) {
            yield [
                'id' => $i,
                'title' => 'Article number '.$i,
                'author' => 'Author '.($i % 500),
                'published_at' => 1_600_000_000 + $i,
                'body' => str_repeat($lorem, 52),
            ];
        }
    }

    private static function articlesConfiguration(): Configuration
    {
        return Configuration::create()
            ->withSearchableAttributes(['title', 'body'])
            ->withFilterableAttributes(['published_at'])
            ->withSortableAttributes(['published_at'])
            ->withLanguages(['en'])
        ;
    }

    private static function articlesIndexPath(): string
    {
        return self::projectRoot().'/var/bench/browse-articles';
    }

    private static function articlesLoupe(): Loupe
    {
        $dataDir = self::articlesIndexPath();

        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }

        return (new LoupeFactory())->create($dataDir, self::articlesConfiguration());
    }

    private static function ensureArticleIndex(): void
    {
        $loupe = self::articlesLoupe();

        if (!$loupe->needsReindex() && self::ARTICLE_COUNT === $loupe->countDocuments()) {
            return;
        }

        unset($loupe);
        self::clearDir(self::articlesIndexPath());

        $loupe = self::articlesLoupe();
        $loupe->addDocuments(DocumentSource::fromFactory(self::articleDocuments(...)));
    }

    /**
     * @param array<string> $attributesToRetrieve
     */
    private function browseAll(Loupe $loupe, int $documentCount, array $attributesToRetrieve): void
    {
        for ($offset = 0; $offset < $documentCount; $offset += self::PAGE_SIZE) {
            $loupe->browse(
                BrowseParameters::create()
                    ->withAttributesToRetrieve($attributesToRetrieve)
                    ->withLimit(self::PAGE_SIZE)
                    ->withOffset($offset),
            );
        }
    }
}
