<?php

namespace Terminal42\Loupe\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Terminal42\Loupe\LoupeFactory;

class IntegrationTest extends TestCase
{
    private function getTestDb(): string
    {
        return __DIR__ . '/../../var/loupe.db';
    }

    private function getDocumentFixtures(string $name): array
    {
        return json_decode(file_get_contents(__DIR__ . '/Fixtures/' . $name . '.json'), true);
    }

    public function setUp(): void
    {
        $fs = new Filesystem();
        $fs->remove($this->getTestDb());
        $fs->dumpFile($this->getTestDb(), '');
    }

    /**
     * @dataProvider integrationTestsProvider
     */
    public function testIntegration(string $fixture, array $configuration, array $search, array $expectedResults): void
    {
        $documents = $this->getDocumentFixtures($fixture);

        $factory = new LoupeFactory();
        $loupe = $factory->create($this->getTestDb(), $configuration);

        foreach ($documents as $document) {
            $loupe->addDocument($document);
        }

        $results = $loupe->search($search);

        //unset($results['processingTimeMs']);

        $this->assertSame($expectedResults, $results['hits']);
    }

    public function integrationTestsProvider(): \Generator
    {
        yield 'foo' => [
            'filters',
            [
                'filterableAttributes' => [
                    'departments',
                    'gender',
                ],
                'sortableAttributes' => [
                    'firstname'
                ]
            ],
            [
                'q' => '',
                'attributesToReceive' => ['id', 'firstname'],
                'filter' => "(departments = 'Development' OR departments = 'Engineering') AND gender = 'female'",
                'sort' => ['firstname:asc']
            ],
            [
                ['id' => 1, 'firstname' => 'Sandra'],
                ['id' => 2, 'firstname' => 'Uta'],
            ]
        ];
    }
}

