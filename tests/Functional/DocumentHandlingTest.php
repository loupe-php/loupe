<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Functional;

use Loupe\Loupe\Configuration;
use PHPUnit\Framework\TestCase;

class DocumentHandlingTest extends TestCase
{
    use FunctionalTestTrait;

    public function testGetDocument(): void
    {
        $configuration = Configuration::create()
            ->withFilterableAttributes(['departments', 'gender'])
            ->withSortableAttributes(['firstname'])
        ;

        $loupe = $this->createLoupe($configuration);
        $this->indexFixture($loupe, 'departments');

        $this->assertSame([
            'id' => 1,
            'firstname' => 'Sandra',
            'lastname' => 'Maier',
            'gender' => 'female',
            'departments' =>
                ['Development', 'Engineering'],
            'colors' =>
                ['Green', 'Blue'],
            'age' => 40,
        ], $loupe->getDocument(1));

        $this->assertSame([
            'id' => 1,
            'firstname' => 'Sandra',
            'lastname' => 'Maier',
            'gender' => 'female',
            'departments' =>
                ['Development', 'Engineering'],
            'colors' =>
                ['Green', 'Blue'],
            'age' => 40,
        ], $loupe->getDocument('1'));

        $this->assertNull($loupe->getDocument('foobar'));
    }
}
