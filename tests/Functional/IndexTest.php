<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Functional;

use Loupe\Loupe\Configuration;
use Loupe\Loupe\Exception\InvalidDocumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

class IndexTest extends TestCase
{
    use FunctionalTestTrait;

    public function testCannotChangeSchema(): void
    {
        $this->expectException(InvalidDocumentException::class);
        $this->expectExceptionMessage(
            'Document ("{"completely":"different-schema"}") does not match schema: {"id":"number","firstname":"string","lastname":"string","gender":"string","departments":"array<string>","colors":"array<string>","age":"number"}'
        );

        $configuration = Configuration::create()
            ->withFilterableAttributes(['departments', 'gender'])
            ->withSortableAttributes(['firstname'])
        ;

        $loupe = $this->createLoupe($configuration);

        $loupe->addDocument($this->getSandraDocument());
        $loupe->addDocument([
            'completely' => 'different-schema',
        ]);
    }

    public function testCannotUseGeoAttributeWithWrongType(): void
    {
        $this->expectException(InvalidDocumentException::class);
        $this->expectExceptionMessage(
            'The "_geo" attribute must have two keys only, which have to be named "lat" and "lng".'
        );

        $loupe = $this->createLoupe(Configuration::create());
        $loupe->addDocument([
            'id' => '42',
            '_geo' => 'incorrect',
        ]);
    }

    public function testReindex(): void
    {
        $fs = new Filesystem();
        $tmpDb = $fs->tempnam(sys_get_temp_dir(), 'lt');

        $configuration = Configuration::create()
            ->withFilterableAttributes(['departments', 'gender'])
            ->withSortableAttributes(['firstname'])
        ;

        $loupe = $this->createLoupe($configuration, $tmpDb);
        $loupe->addDocument($this->getSandraDocument());

        $this->assertFalse($loupe->needsReindex());

        $configuration = Configuration::create()
            ->withSearchableAttributes(['firstname'])
        ;

        $loupe = $this->createLoupe($configuration, $tmpDb);

        // Just making sure that it was actually persistent
        $this->assertSame(1, $loupe->countDocuments());

        $this->assertTrue($loupe->needsReindex());

        $fs->remove($tmpDb);
    }

    public function testReplacingTheSameDocumentWorks(): void
    {
        $configuration = Configuration::create()
            ->withFilterableAttributes(['departments', 'gender'])
            ->withSortableAttributes(['firstname'])
        ;

        $loupe = $this->createLoupe($configuration);

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
