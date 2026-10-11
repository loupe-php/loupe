<?php

declare(strict_types=1);

namespace Loupe\Loupe\Tests\Functional;

use Loupe\Loupe\Configuration;
use Loupe\Loupe\Json\JsonInterface;
use Loupe\Loupe\Json\NativeJson;
use Loupe\Loupe\SearchParameters;
use PHPUnit\Framework\TestCase;

final class DocumentHandlingTest extends TestCase
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

        $this->assertSame(
            [
                'id' => 1,
                'firstname' => 'Sandra',
                'lastname' => 'Maier',
                'gender' => 'female',
                'departments' => ['Development', 'Engineering'],
                'colors' => ['Green', 'Blue'],
                'age' => 40,
                'isActive' => true,
                'recentPerformanceScores' => [4.5, 4.7, 4.6],
            ],
            $loupe->getDocument(1),
        );

        $this->assertSame(
            [
                'id' => 1,
                'firstname' => 'Sandra',
                'lastname' => 'Maier',
                'gender' => 'female',
                'departments' => ['Development', 'Engineering'],
                'colors' => ['Green', 'Blue'],
                'age' => 40,
                'isActive' => true,
                'recentPerformanceScores' => [4.5, 4.7, 4.6],
            ],
            $loupe->getDocument('1'),
        );

        $this->assertNull($loupe->getDocument('foobar'));
    }

    public function testCustomJsonIsUsedForIndexingAndReading(): void
    {
        $json = new class() implements JsonInterface {
            public int $encodes = 0;

            public int $decodes = 0;

            private NativeJson $native;

            public function __construct()
            {
                $this->native = new NativeJson();
            }

            public function decode(string $json): array
            {
                ++$this->decodes;

                return $this->native->decode($json);
            }

            public function encode(array $data, int $flags = 0): string
            {
                ++$this->encodes;

                return $this->native->encode($data, $flags);
            }

            public function validate(string $json): bool
            {
                return $this->native->validate($json);
            }
        };

        $loupe = $this->createLoupe(Configuration::create()->withJson($json));
        $loupe->addDocuments([['id' => 1, 'title' => 'Example']]);
        $this->assertGreaterThan(0, $json->encodes);

        $this->assertSame(['id' => 1, 'title' => 'Example'], $loupe->getDocument(1));
        $this->assertGreaterThan(0, $json->decodes);
        $this->assertSame('Example', $loupe->search(SearchParameters::create()->withQuery('Example'))->getHits()[0]['title']);
    }

    public function testGetDocumentWhenNotSetUpYet(): void
    {
        $configuration = Configuration::create()
            ->withFilterableAttributes(['departments', 'gender'])
            ->withSortableAttributes(['firstname'])
        ;

        $loupe = $this->createLoupe($configuration);

        $document = $loupe->getDocument('not_existing_identifier');
        $this->assertNull($document);
    }

    public function testSize(): void
    {
        $configuration = Configuration::create()
            ->withFilterableAttributes(['departments', 'gender'])
            ->withSortableAttributes(['firstname'])
        ;

        $loupe = $this->createLoupe($configuration);
        $sizeBefore = $loupe->size();
        $this->assertSame(0, $sizeBefore);

        $this->indexFixture($loupe, 'departments');

        $this->assertGreaterThan($sizeBefore, $loupe->size());
    }
}
