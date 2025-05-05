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
            'departments' => ['Development', 'Engineering'],
            'colors' => ['Green', 'Blue'],
            'age' => 40,
            'isActive' => true,
            'recentPerformanceScores' => [4.5, 4.7, 4.6],
        ], $loupe->getDocument(1));

        $this->assertSame([
            'id' => 1,
            'firstname' => 'Sandra',
            'lastname' => 'Maier',
            'gender' => 'female',
            'departments' => ['Development', 'Engineering'],
            'colors' => ['Green', 'Blue'],
            'age' => 40,
            'isActive' => true,
            'recentPerformanceScores' => [4.5, 4.7, 4.6],
        ], $loupe->getDocument('1'));

        $this->assertNull($loupe->getDocument('foobar'));
    }

    public function testGetDocumentWhenNotSetUpYet(): void
    {
        $configuration = Configuration::create()
            ->withFilterableAttributes(['departments', 'gender'])
            ->withSortableAttributes(['firstname']);

        $loupe = $this->createLoupe($configuration);

        $document = $loupe->getDocument('not_existing_identifier');
        $this->assertNull($document);
    }

    public function testSize(): void
    {
        $configuration = Configuration::create()
            ->withFilterableAttributes(['departments', 'gender'])
            ->withSortableAttributes(['firstname']);

        $loupe = $this->createLoupe($configuration);
        $sizeBefore = $loupe->size();
        $this->assertGreaterThan(0, $sizeBefore);

        $this->indexFixture($loupe, 'departments');

        $this->assertGreaterThan($sizeBefore, $loupe->size());
    }
}
