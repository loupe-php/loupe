<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Unit\Internal\LanguageDetection;

use Loupe\Loupe\Internal\LanguageDetection\NitotmLanguageDetector;
use PHPUnit\Framework\TestCase;

class NitotmLanguageDetectorTest extends TestCase
{
    public function testWeightedLanguageDetection(): void
    {
        $detector = new NitotmLanguageDetector([]);
        $detectionResult = $detector->detectForDocument([
            'title' => 'Die Hard', // If we wouldn't weigh, this entire document would be detected as "nl" because "Die Hard" looks very Dutch apparently
            'overview' => 'NYPD cop John McClane\'s plan to reconcile with his estranged wife is thrown for a serious loop when, minutes after he arrives at her office, the entire building is overtaken by a group of terrorists. With little help from the LAPD, wisecracking McClane sets out to single-handedly rescue the hostages and bring the bad guys down.',
        ]);

        $this->assertSame('en', $detectionResult->getBestLanguageForAttribute('overview'));
        $this->assertSame('en', $detectionResult->getBestLanguageForDocument());
    }
}
