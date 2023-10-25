# Changelog

Contains only a redacted list of new features. For bugfixes and more details, checkout the git commit history.

## 0.5.0

* PERFORMANCE! Loupe 0.5 now searches the 32k movies.json database for `Amakin Dkywalker` with typo tolerance 
  enabled and ordered by relevance in about `80 ms` 🚀
* Faster State Set Index implementation (work happened in https://github.com/Toflar/state-set-index)
* State Set is now added as PHP file on top for OPCache improvements when using persistance - this means that there 
  is an API change for `LoupeFactory::create()` which now requires a directory instead of a database-only path!
* Prefix search now with typo tolerance! This is not enabled by default because of performance reasons but you can 
  do so using `TypoTolerance::create()->withEnabledForPrefixSearch(true)`.
* Faster language detection when limiting the languages using `Configuration::create()->withLanguages(['en', 'de'])`
* Entire new documentation section about performance!
* Additional micro optimizations
* We know have a `CHANGELOG.md` so devs can see the major changes in every version very quickly

## 0.4.0

* Both `addDocuments()` and `addDocument()` now return an `IndexResult`. This means that when there's an issue in a  
  document, `addDocuments()` now continues to index the rest of the documents and does not abort immediately 
   anymore. `IndexResult` gives you access to the summary and individual exceptions of documents.
* Added `SearchParameters::getHash()` to identify a search query to allow for customized caching.
* Prefer the sqlite3 driver over pdo-sqlite for performance reasons.
* Uses the connection to determine the SQLite version instead of a separate query (better performance)

## 0.3.0

* Entirely new language detection algorithm. Supported languages for detection can now be configured:

    ```php
    $configuration = Configuration::create()
        ->withLanguages(['en', 'fr', 'de'])
    ;
    ```
* Documented `Tokenizer` internals
* Massively improved indexing performance. Based on the [MeiliSearch 32k movies database](https://www.meilisearch.com/movies.json)
  we started out at about 15min (~35 documents per second) on version 0.2. Version 0.3 completes in less than 4min 
  (~140 documents per second) 😎

## 0.2.0

* Added support for deleting documents:
    ```php
    $loupe->deleteDocuments();
    $loupe->deleteDocument();
    ```
* Better performance and the docs now contain a section about what you can expect from Loupe.
* Added support for the `!=` operator
* Added support for the `IS NULL` and `IS NOT NULL` operator
* Added support for the `IS EMPTY` and `IS NOT EMPTY` operator
* All operators now also work on multi value attributes. So you can search attributes like `departments = ['foo', 'bar']` using `=`, `!=`, `>=` etc.
* Added better logging. All SQL queries are now logged, so you know exactly what's going on in Loupe.
* You can now only search for a subset of the searchable attributes:

    ```php
    $searchParameters = SearchParameters::create()
        ->withAttributesToSearchOn(['firstname'])
    ;
    ```
* You can now ask Loupe for the ranking score which will be a `_rankingScore` attribute on every hit:

    ```php
    $searchParameters = SearchParameters::create()
        ->withShowRankingScore(true)
    ;
    ```
* Typo tolerance can now be disabled a lot easier:

    ```php
    $configuration = Configuration::create()
        ->withTypoTolerance(TypoTolerance::disabled())
    ;
    ```
* Improved code quality. Loupe is now tested against PHPStan Level 8
* Reworked and documented document schema

## 0.1.0 - Initial version 🎉

An SQLite based, PHP-only fulltext search engine.

Loupe…

* …only requires PHP and SQLite, you don't need anything else - no containers, no nothing
* …is typo-tolerant (based on the State Set Index Algorithm and Levenshtein)
* …supports phrase search using " quotation marks
* …supports filtering (and ordering) on any attribute with any SQL-inspired filter statement
* …supports filtering (and ordering) on Geo distance
* …orders relevance based on a typical TF-IDF Cosine similarity algorithm
* …auto-detects languages
* …supports stemming
* …is very easy to use
* …is all-in-all just the easiest way to replace your good old SQL LIKE %...% queries with a way better search 
  experience but without all the hassle of an additional service to manage. SQLite is everywhere and all it needs is
  your filesystem.
