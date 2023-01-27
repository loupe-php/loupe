<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Tests\Functional;

class DocumentHandlingTest extends AbstractFunctionalTest
{
    public function testGetDocument(): void
    {
        $loupe = $this->setupLoupe([
            'filterableAttributes' => ['departments', 'gender'],
            'sortableAttributes' => ['firstname'],
        ], 'filters');

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
