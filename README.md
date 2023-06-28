# Loupe

An SQLite based, PHP-only fulltext search engine.

Loupe…

* …is completely dependency-free (other than PHP and SQLite, you don't need anything - no containers, no nothing)
* …is typo-tolerant (based on the State Set Index Algorithm and Levenshtein)
* …supports phrase search using `"` quotation marks
* …supports filtering (and ordering) on any attribute with any SQL-inspired filter statement
* …supports filtering (and ordering) on Geo distance
* …orders relevance based on a typical TF-IDF Cosine similarity algorithm
* …auto-detects languages
* …supports stemming
* …is very easy to use
* …is easily fast enough for at least 50k documents (50k is defensive)
* …is all-in-all just the easiest way to replace your good old SQL `LIKE %...%` queries with a way better search 
  experience but without all the hassle of an additional service to manage. SQLite is everywhere and all it needs is 
  your filesystem.

## Acknowledgement

If you are familiar with MeiliSearch, you will notice that the API is very much inspired by it. The
reasons for this are simple:

1. First and foremost: I think, they did an amazing job of keeping configuration simple and understandable from a 
   developer's perspective. Basic search tools shouldn't be complicated.
2. If Loupe shouldn't be enough for your use case anymore (you need advanced features, better performance etc.), 
   switching to MeiliSearch instead should be as easy as possible.

I even took the liberty to copy some of their test data to feed Loupe for functional tests.

## Usage

```php
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

// Persist your database:
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
```

## Configuration

See [Configuration](./docs/configuration.md).

## Searching

See [Searching](./docs/searching.md).
