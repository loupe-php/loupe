<?php

declare(strict_types=1);

namespace Terminal42\Loupe\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Terminal42\Loupe\LoupeFactory;

class FunctionalTest extends TestCase
{
    protected function setUp(): void
    {
        $fs = new Filesystem();
        $fs->remove($this->getTestDb());
        $fs->dumpFile($this->getTestDb(), '');
    }

    public function integrationTestsProvider(): \Generator
    {
        yield 'Test case with complex filter' => [
            'filters',
            [
                'filterableAttributes' => ['departments', 'gender'],
                'sortableAttributes' => ['firstname'],
            ],
            [
                'q' => '',
                'attributesToReceive' => ['id', 'firstname'],
                'filter' => "(departments = 'Backoffice' OR departments = 'Project Management') AND gender = 'female'",
                'sort' => ['firstname:asc'],
            ],
            [
                'hits' => [
                    [
                        'id' => 2,
                        'firstname' => 'Uta',
                    ],
                ],
                'query' => '',
                'hitsPerPage' => 20,
                'page' => 1,
                'totalPages' => 1,
                'totalHits' => 1,
            ],
            10, // Must finish in less than 10ms
        ];

        yield 'Test with 5000 movies and a bit more complex query' => [
            'movies_5000',
            [
                'filterableAttributes' => ['genres', 'release_date'],
                'sortableAttributes' => ['title'],
            ],
            [
                'q' => '',
                'attributesToReceive' => ['id', 'title'],
                'filter' => "(genres = 'WAR' OR genres = 'Adventure') AND release_date > 0",
                'sort' => ['title:asc'],
            ],
            [
                'hits' => [
                    [
                        'id' => 7840,
                        'title' => '10,000 BC',
                    ],
                    [
                        'id' => 1492,
                        'title' => '1492: Conquest of Paradise',
                    ],
                    [
                        'id' => 2207,
                        'title' => '16 Blocks',
                    ],
                    [
                        'id' => 9510,
                        'title' => '1½ Knights - In Search of the Ravishing Princess Herzelinde',
                    ],
                    [
                        'id' => 2965,
                        'title' => '20,000 Leagues Under the Sea',
                    ],
                    [
                        'id' => 1271,
                        'title' => '300',
                    ],
                    [
                        'id' => 12138,
                        'title' => '3000 Miles to Graceland',
                    ],
                    [
                        'id' => 12244,
                        'title' => '9',
                    ],
                    [
                        'id' => 9487,
                        'title' => "A Bug's Life",
                    ],
                    [
                        'id' => 530,
                        'title' => 'A Grand Day Out',
                    ],
                    [
                        'id' => 9476,
                        'title' => "A Knight's Tale",
                    ],
                    [
                        'id' => 12403,
                        'title' => 'A Perfect Getaway',
                    ],
                    [
                        'id' => 10077,
                        'title' => 'A Sound of Thunder',
                    ],
                    [
                        'id' => 707,
                        'title' => 'A View to a Kill',
                    ],
                    [
                        'id' => 644,
                        'title' => 'A.I. Artificial Intelligence',
                    ],
                    [
                        'id' => 395,
                        'title' => 'AVP: Alien vs. Predator',
                    ],
                    [
                        'id' => 2701,
                        'title' => 'Abraham',
                    ],
                    [
                        'id' => 9273,
                        'title' => 'Ace Ventura: When Nature Calls',
                    ],
                    [
                        'id' => 10117,
                        'title' => 'Action Jackson',
                    ],
                    [
                        'id' => 11540,
                        'title' => 'Adventures of Arsene Lupin',
                    ],
                ],
                'query' => '',
                'hitsPerPage' => 20,
                'page' => 1,
                'totalPages' => 36,
                'totalHits' => 713,
            ],
            10, // Must finish in less than 10ms
        ];
    }

    /**
     * @dataProvider integrationTestsProvider
     */
    public function testIntegration(
        string $fixture,
        array $configuration,
        array $search,
        array $expectedResults,
        int $expectedMaxProcessingTime
    ): void {
        $documents = $this->getDocumentFixtures($fixture);

        $factory = new LoupeFactory();
        $loupe = $factory->create($this->getTestDb(), $configuration);

        foreach ($documents as $document) {
            try {
                $loupe->addDocument($document);
            } catch (\Exception) {
                // TODO: Should be able to replace an existing document.
                continue;
            }
        }

        $results = $loupe->search($search);

        $this->assertLessThanOrEqual(
            $expectedMaxProcessingTime,
            $results['processingTimeMs'],
            'Performance degradation?'
        );
        unset($results['processingTimeMs']);

        $this->assertSame($expectedResults, $results);
    }

    private function getDocumentFixtures(string $name): array
    {
        return json_decode(file_get_contents(__DIR__ . '/Fixtures/' . $name . '.json'), true);
    }

    private function getTestDb(): string
    {
        return __DIR__ . '/../../var/loupe.db';
    }
}
