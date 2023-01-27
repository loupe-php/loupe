<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Tests\Functional;

use Terminal42\Loupe\Exception\InvalidDocumentException;

class IndexTest extends AbstractFunctionalTest
{
    public function testCannotChangeSchema(): void
    {
        $this->expectException(InvalidDocumentException::class);
        $this->expectExceptionMessage(
            'Document ("{"completely":"different-schema"}") does not match schema: {"id":"number","firstname":"string","lastname":"string","gender":"string","departments":"array<string>","colors":"array<string>","age":"number"}'
        );

        $loupe = $this->setupLoupe([
            'filterableAttributes' => ['departments', 'gender'],
            'sortableAttributes' => ['firstname'],
        ]);

        $loupe->addDocument($this->getSandraDocument());
        $loupe->addDocument([
            'completely' => 'different-schema',
        ]);
    }

    public function testReplacingTheSameDocumentWorks(): void
    {
        $loupe = $this->setupLoupe([
            'filterableAttributes' => ['departments', 'gender'],
            'sortableAttributes' => ['firstname'],
        ]);

        $sandra = $this->getSandraDocument();

        $uta = [
            'id' => 1, // Same ID, should replace
            'firstname' => 'Uta',
            'lastname' => 'Koertig',
            'gender' => 'female',
            'departments' => ['Development', 'Backoffice'],
            'colors' => ['Red', 'Orange'],
            'age' => 29,
        ];

        $loupe->addDocument($sandra);
        $document = $loupe->getDocument(1);
        $this->assertSame($sandra, $document);

        $loupe->addDocument($uta);
        $document = $loupe->getDocument(1);
        $this->assertSame($uta, $document);
    }

    private function getSandraDocument(): array
    {
        return [
            'id' => 1,
            'firstname' => 'Sandra',
            'lastname' => 'Maier',
            'gender' => 'female',
            'departments' => ['Development', 'Engineering'],
            'colors' => ['Green', 'Blue'],
            'age' => 40,
        ];
    }
}
