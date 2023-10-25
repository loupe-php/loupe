<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Unit\Internal\Config;

use Loupe\Loupe\Config\TypoTolerance;
use PHPUnit\Framework\TestCase;

class TypoToleranceTest extends TestCase
{
    public function testDefaults(): void
    {
        $typoTolerance = new TypoTolerance();

        $this->assertSame(4, $typoTolerance->getAlphabetSize());
        $this->assertSame(14, $typoTolerance->getIndexLength());
        $this->assertSame(0, $typoTolerance->getLevenshteinDistanceForTerm('1234'));
        $this->assertSame(1, $typoTolerance->getLevenshteinDistanceForTerm('12345'));
        $this->assertSame(2, $typoTolerance->getLevenshteinDistanceForTerm('123456789'));
        $this->assertFalse($typoTolerance->isEnabledForPrefixSearch());
    }

    public function testWithDisabledTypoTolerance(): void
    {
        $typoTolerance = new TypoTolerance();
        $typoTolerance = $typoTolerance->disable();

        $this->assertSame(0, $typoTolerance->getLevenshteinDistanceForTerm('12'));
        $this->assertSame(0, $typoTolerance->getLevenshteinDistanceForTerm('123'));
        $this->assertSame(0, $typoTolerance->getLevenshteinDistanceForTerm('12345678'));
        $this->assertSame(0, $typoTolerance->getLevenshteinDistanceForTerm('123456789'));
    }

    public function testWithers(): void
    {
        $typoTolerance = new TypoTolerance();
        $typoTolerance = $typoTolerance->withAlphabetSize(10);
        $typoTolerance = $typoTolerance->withIndexLength(8);
        $typoTolerance = $typoTolerance->withTypoThresholds([
            8 => 2,
            3 => 1,
        ]);
        $typoTolerance = $typoTolerance->withEnabledForPrefixSearch(true);

        $this->assertSame(10, $typoTolerance->getAlphabetSize());
        $this->assertSame(8, $typoTolerance->getIndexLength());
        $this->assertSame(0, $typoTolerance->getLevenshteinDistanceForTerm('12'));
        $this->assertSame(1, $typoTolerance->getLevenshteinDistanceForTerm('123'));
        $this->assertSame(2, $typoTolerance->getLevenshteinDistanceForTerm('12345678'));
        $this->assertSame(2, $typoTolerance->getLevenshteinDistanceForTerm('123456789'));
        $this->assertTrue($typoTolerance->isEnabledForPrefixSearch());
    }

    public function testWrongThresholdOrderIsFixedAutomatically(): void
    {
        $typoTolerance = new TypoTolerance();
        $typoTolerance = $typoTolerance->withTypoThresholds([
            3 => 1,
            8 => 2,
        ]);

        $this->assertSame(0, $typoTolerance->getLevenshteinDistanceForTerm('12'));
        $this->assertSame(1, $typoTolerance->getLevenshteinDistanceForTerm('123'));
        $this->assertSame(2, $typoTolerance->getLevenshteinDistanceForTerm('12345678'));
        $this->assertSame(2, $typoTolerance->getLevenshteinDistanceForTerm('123456789'));
    }
}
