<?php

namespace Terminal42\Loupe\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Terminal42\Loupe\LoupeFactory;

class IntegrationTest extends TestCase
{
    private function getTestDb(): string
    {
        return __DIR__ . '/../var/loupe.db';
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

    public function testIntegration(): void
    {
        $documents = $this->getDocumentFixtures('movies_short');

        $factory = new LoupeFactory();
        $loupe = $factory->create($this->getTestDb(), [
            'indexes' => [
                'movies' => [
                    'filterableAttributes' => [
                        'genres'
                    ],
                    'sortableAttributes' => [
                        'title'
                    ]
                    /*
                    "typoTolerance" => [
                        "enabled" => true,
                        "minWordSizeForTypos" => [
                            "oneTypo" => 5,
                            "twoTypos" => 9
                        ],
                        "disableOnWords" => [
                        ],
                        "disableOnAttributes" => [
                        ]
                    ]*/
                ]
            ]
        ]);

        $loupe->createSchema();
        $movies = $loupe->getIndex('movies');

        foreach ($documents as $document) {
            $movies->addDocument($document);
        }

        $results = $movies->search([
            'q' => '',
            'filter' => 'genres = "Drama"',
            'sort' => ['title:asc']
        ]);

        $this->assertSame([], $results);
    }
}