<?php

namespace App;

require_once 'vendor/autoload.php';

use Terminal42\Loupe\Config\TypoTolerance;
use Terminal42\Loupe\Configuration;
use Terminal42\Loupe\LoupeFactory;
use Terminal42\Loupe\SearchParameters;

$configuration = Configuration::create()
    ->withPrimaryKey('uuid') // optional, by default it's 'id'
    ->withSearchableAttributes(['firstname', 'lastname']) // optional, by default it's ['*'] - everything is indexed
    ->withFilterableAttributes(['departments', 'age'])
    ->withSortableAttributes(['lastname'])
    ->withTypoTolerance(TypoTolerance::create()->withFirstCharTypoCountsDouble(false)) // can be further fine-tuned but is enabled by default
;

$loupeFactory = new LoupeFactory();
$loupe = $loupeFactory->createInMemory($configuration);

// if you want to use persistence:
// $loupe = $loupeFactory->create($dbPath, $configuration);


$loupe->addDocuments([
    [
        'uuid' => 2,
        'firstname' => 'Uta',
        'lastname' => 'Koertig',
        'departments' => [
            'Development',
            'Backoffice',
        ],
        'age' => 29,
    ],
    [
        'uuid' => 6,
        'firstname' => 'Huckleberry',
        'lastname' => 'Finn',
        'departments' => [
            'Backoffice',
        ],
        'age' => 18,
    ],
]);


$searchParameters = SearchParameters::create()
    ->withQuery('Gucleberry')
    ->withAttributesToRetrieve(['id', 'firstname'])
    ->withFilter("(departments = 'Backoffice' OR departments = 'Project Management') AND age > 17")
    ->withSort(['lastname:asc'])
;

$results = $loupe->search($searchParameters);

print_r($results);

/*
Array
(
    [hits] => Array
        (
            [0] => Array
                (
                    [firstname] => Huckleberry
                )

        )

    [query] => Gucleberry
    [processingTimeMs] => 4
    [hitsPerPage] => 20
    [page] => 1
    [totalPages] => 1
    [totalHits] => 1
)
*/