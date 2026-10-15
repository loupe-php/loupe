<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Benchmark;

use Loupe\Loupe\Config\TypoTolerance;
use Loupe\Loupe\Configuration;
use Loupe\Loupe\Loupe;
use Loupe\Loupe\LoupeFactory;
use Loupe\Loupe\SearchParameters;
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
#[Warmup(1)]
#[OutputTimeUnit('milliseconds', precision: 2)]
#[Groups(['formatting'])]
final class FormattingBench extends AbstractBench
{
    private const DOCUMENT_COUNT = 4;

    private Loupe $loupe;

    public function setUp(): void
    {
        $this->loupe = (new LoupeFactory())->create(self::formattingIndexPath(), self::formattingConfiguration());
    }

    public static function setUpClass(): void
    {
        $dataDir = self::formattingIndexPath();

        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0777, true);
        }

        $loupe = (new LoupeFactory())->create($dataDir, self::formattingConfiguration());

        if (!$loupe->needsReindex() && self::DOCUMENT_COUNT === $loupe->countDocuments()) {
            return;
        }

        unset($loupe);
        self::clearDir($dataDir);

        $loupe = (new LoupeFactory())->create($dataDir, self::formattingConfiguration());
        $loupe->addDocuments(self::formattingDocuments());
    }

    public function benchHighlightTitleOnlyShortBody(): void
    {
        $this->highlightTitle('alpha');
    }

    public function benchHighlightTitleOnlyDenseBody2k(): void
    {
        $this->highlightTitle('beta');
    }

    public function benchHighlightTitleOnlyDenseBody4k(): void
    {
        $this->highlightTitle('gamma');
    }

    public function benchHighlightTitleOnlyNonMatchingBody(): void
    {
        $this->highlightTitle('delta');
    }

    public function benchHighlightBodyDense2k(): void
    {
        $this->loupe->search($this->search('beta')->withAttributesToHighlight(['body']));
    }

    public function benchHighlightTitleAndBodyDense2k(): void
    {
        $this->loupe->search($this->search('beta')->withAttributesToHighlight(['title', 'body']));
    }

    public function benchCropTitleOnlyDenseBody2k(): void
    {
        $this->loupe->search($this->search('beta')->withAttributesToCrop(['title']));
    }

    private static function formattingConfiguration(): Configuration
    {
        return Configuration::create()
            ->withSearchableAttributes(['title', 'body'])
            ->withLanguages(['en'])
            ->withTypoTolerance(TypoTolerance::create()->disable())
            ->withVacuumProbability(0)
        ;
    }

    /**
     * @return list<array{id: int, title: string, body: string}>
     */
    private static function formattingDocuments(): array
    {
        return [
            ['id' => 1, 'title' => 'Alpha lorem', 'body' => str_repeat('alpha ', 100)],
            ['id' => 2, 'title' => 'Beta lorem', 'body' => str_repeat('beta ', 2_000)],
            ['id' => 3, 'title' => 'Gamma lorem', 'body' => str_repeat('gamma ', 4_000)],
            ['id' => 4, 'title' => 'Delta lorem', 'body' => str_repeat('quick brown fox ', 1_500)],
        ];
    }

    private static function formattingIndexPath(): string
    {
        return self::projectRoot().'/var/bench/formatting';
    }

    private function highlightTitle(string $term): void
    {
        $this->loupe->search($this->search($term)->withAttributesToHighlight(['title']));
    }

    private function search(string $term): SearchParameters
    {
        return SearchParameters::create()->withQuery($term);
    }
}
