<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Unit;

use Loupe\Loupe\Exception\InvalidSearchParametersException;
use Loupe\Loupe\SearchParameters;
use PHPUnit\Framework\TestCase;

class SearchParametersTest extends TestCase
{
    public function testHash(): void
    {
        $searchParameters = SearchParameters::create();

        $this->assertNotSame(
            $searchParameters->getHash(),
            $searchParameters->withPage(2)->getHash()
        );

        $this->assertNotSame(
            $searchParameters->getHash(),
            $searchParameters->withStopWords(['a', 'the'])->getHash()
        );
    }

    public function testMaxHitsPerPage(): void
    {
        $this->expectException(InvalidSearchParametersException::class);
        $this->expectExceptionMessage('Cannot request more than 1000 documents per request, use pagination.');

        SearchParameters::create()->withHitsPerPage(2000);
    }
}
