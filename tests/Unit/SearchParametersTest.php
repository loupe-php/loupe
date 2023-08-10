<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Unit;

use Loupe\Loupe\Exception\InvalidSearchParametersException;
use Loupe\Loupe\SearchParameters;
use PHPUnit\Framework\TestCase;

class SearchParametersTest extends TestCase
{
    public function testMaxHitsPerPage(): void
    {
        $this->expectException(InvalidSearchParametersException::class);
        $this->expectExceptionMessage('Cannot request more than 1000 documents per request, use pagination.');

        SearchParameters::create()->withHitsPerPage(2000);
    }
}
