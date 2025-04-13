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
    }

    public function testImmutability(): void
    {
        $params = SearchParameters::create();
        $newParams = $params->withPage(2);

        $this->assertNotSame($params, $newParams);
        $this->assertSame(1, $params->getPage());
        $this->assertSame(2, $newParams->getPage());
    }

    public function testMaxHitsPerPage(): void
    {
        $this->expectException(InvalidSearchParametersException::class);
        $this->expectExceptionMessage('Cannot request more than 1000 documents per request, use pagination.');

        SearchParameters::create()->withHitsPerPage(2000);
    }

    public function testToArrayAndFromArray(): void
    {
        $original = SearchParameters::create()
            ->withQuery('hello world')
            ->withPage(3)
            ->withHitsPerPage(50)
            ->withRankingScoreThreshold(0.25)
            ->withFilter("status = 'active'")
            ->withAttributesToRetrieve(['title', 'author'])
            ->withAttributesToHighlight(['title'], '<strong>', '</strong>')
            ->withAttributesToSearchOn(['title'])
            ->withShowMatchesPosition(true)
            ->withShowRankingScore(true)
            ->withSort(['popularity:desc']);

        $array = $original->toArray();
        $reconstructed = SearchParameters::fromArray($array);

        $this->assertSame($original->toArray(), $reconstructed->toArray());
    }

    public function testToStringAndFromString(): void
    {
        $params = SearchParameters::create()->withQuery('test');
        $json = $params->toString();
        $parsed = SearchParameters::fromString($json);

        $this->assertSame($params->toArray(), $parsed->toArray());
    }
}
