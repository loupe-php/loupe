<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Tests\Unit\Internal\Config;

use PHPUnit\Framework\TestCase;
use Terminal42\Loupe\Config\TypoTolerance;

class TypoToleranceTest extends TestCase
{
    public function testDefaults(): void
    {
        $typoTolerance = new TypoTolerance();

        $this->assertSame(20, $typoTolerance->getAlphabetSize());
        $this->assertSame(16, $typoTolerance->getIndexLength());
        $this->assertSame(0, $typoTolerance->getLevenshteinDistanceForTerm('1234'));
        $this->assertSame(1, $typoTolerance->getLevenshteinDistanceForTerm('12345'));
        $this->assertSame(2, $typoTolerance->getLevenshteinDistanceForTerm('123456789'));
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
        $typoTolerance = $typoTolerance->withTermThresholds([
            8 => 2,
            3 => 1,
        ]);

        $this->assertSame(10, $typoTolerance->getAlphabetSize());
        $this->assertSame(8, $typoTolerance->getIndexLength());
        $this->assertSame(0, $typoTolerance->getLevenshteinDistanceForTerm('12'));
        $this->assertSame(1, $typoTolerance->getLevenshteinDistanceForTerm('123'));
        $this->assertSame(2, $typoTolerance->getLevenshteinDistanceForTerm('12345678'));
        $this->assertSame(2, $typoTolerance->getLevenshteinDistanceForTerm('123456789'));
    }

    public function testWrongThresholdOrderIsFixedAutomatically(): void
    {
        $typoTolerance = new TypoTolerance();
        $typoTolerance = $typoTolerance->withTermThresholds([
            3 => 1,
            8 => 2,
        ]);

        $this->assertSame(0, $typoTolerance->getLevenshteinDistanceForTerm('12'));
        $this->assertSame(1, $typoTolerance->getLevenshteinDistanceForTerm('123'));
        $this->assertSame(2, $typoTolerance->getLevenshteinDistanceForTerm('12345678'));
        $this->assertSame(2, $typoTolerance->getLevenshteinDistanceForTerm('123456789'));
    }
}
