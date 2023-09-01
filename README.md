# Loupe

An SQLite based, PHP-only fulltext search engine.

Loupe…

* …only requires PHP and SQLite, you don't need anything else - no containers, no nothing
* …is typo-tolerant (based on the State Set Index Algorithm and Levenshtein)
* …supports phrase search using `"` quotation marks
* …supports filtering (and ordering) on any attribute with any SQL-inspired filter statement
* …supports filtering (and ordering) on Geo distance
* …orders relevance based on a typical TF-IDF Cosine similarity algorithm
* …auto-detects languages
* …supports stemming
* …is very easy to use
* …is all-in-all just the easiest way to replace your good old SQL `LIKE %...%` queries with a way better search 
  experience but without all the hassle of an additional service to manage. SQLite is everywhere and all it needs is 
  your filesystem.

## Introductory blog post

If this is your first encounter with Loupe, you might want to read the [blog post on Medium][Blog_Medium] or [as 
Markdown file][Blog_Repository] that should give you more information about the reasons and what you can do with it.

## Performance

Performance depends on many factors but here are some ballpark numbers based on indexing the [~32k movies fixture by 
MeiliSearch][MeiliSearch_Movies]:

* Indexing will take less than 4min (~140 documents per second)
* The slowest possible search query (typo tolerance enabled, ordered by relevance) finishes in < 400ms

Note that anything above 50k documents is probably not a use case for Loupe. You may report your own performance 
measurements and more details in the [respective discussion][Performance_Topic].

## Acknowledgement

If you are familiar with MeiliSearch, you will notice that the API is very much inspired by it. The
reasons for this are simple:

1. First and foremost: I think, they did an amazing job of keeping configuration simple and understandable from a 
   developer's perspective. Basic search tools shouldn't be complicated.
2. If Loupe shouldn't be enough for your use case anymore (you need advanced features, better performance etc.), 
   switching to MeiliSearch instead should be a piece of cake.

I even took the liberty to copy some of their test data to feed Loupe for functional tests.

## Installation

1. Make sure you have `pdo_sqlite` available and your installed SQLite version is at least 3.16.0. This is when 
   PRAGMA functions have been added without which no schema comparisons are possible. It is recommended you run at 
   least version 3.35.0 which is when mathematical functions found its way into SQLite. Otherwise, Loupe has to 
   polyfill those which will result in a little performance penalty.
2. Run `composer require loupe/loupe`.

## Usage

```php
<?php

namespace App;

require_once 'vendor/autoload.php';

use Loupe\Loupe\Config\TypoTolerance;
use Loupe\Loupe\Configuration;
use Loupe\Loupe\LoupeFactory;
use Loupe\Loupe\SearchParameters;

$configuration = Configuration::create()
    ->withPrimaryKey('uuid') // optional, by default it's 'id'
    ->withSearchableAttributes(['firstname', 'lastname']) // optional, by default it's ['*'] - everything is indexed
    ->withFilterableAttributes(['departments', 'age'])
    ->withSortableAttributes(['lastname'])
    ->withTypoTolerance(TypoTolerance::create()->withFirstCharTypoCountsDouble(false)) // can be further fine-tuned but is enabled by default
;

$loupeFactory = new LoupeFactory();

$loupe = $loupeFactory->create('path/to/my_loupe.db', $configuration);

// or create in-memory search:
$loupe = $loupeFactory->createInMemory($configuration);

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

print_r($results->toArray());

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

## Docs

* [Schema](./docs/schema.md)
* [Configuration](./docs/configuration.md)
* [Indexing](./docs/indexing.md)
* [Searching](./docs/searching.md)
* [Tokenizer](./docs/tokenizer.md)

"Why Loupe?" you ask? "Loupe" means "magnifier" in French and I felt like this was the appropriate choice for this 
library after having given [my PHP crawler library][Escargot] a French name :-)

[Escargot]: https://github.com/terminal42/escargot
[Blog_Medium]: https://medium.com/@yanick.witschi/loupe-a-search-engine-with-only-php-and-sqlite-1c0d83024a71
[Blog_Repository]: ./docs/blog_post.md
[MeiliSearch_Movies]: https://www.meilisearch.com/movies.json
[Performance_Topic]: https://github.com/loupe-php/loupe/discussions/17
