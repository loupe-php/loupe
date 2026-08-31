# Indexing

## Adding documents

There are two methods to index documents in Loupe. Either you index only one document like so:

```php
$loupe->addDocument([
    'id' => 12,
    'title' => 'Finding Nemo',
    'overview' => 'Nemo, an adventurous young clownfish, …',
    'genres' => ['Animation', 'Family']
]);
```

Or you can index multiple documents:

```php
$loupe->addDocuments([
    [
        'id' => 11,
        'title' => 'Star Wars',
        'overview' => 'Princess Leia is captured and held hostage by the evil Imperial forces …',
        'genres' => ['Adventure', 'Action', 'Science Fiction']
    ],
    [
        'id' => 12,
        'title' => 'Finding Nemo',
        'overview' => 'Nemo, an adventurous young clownfish, …',
        'genres' => ['Animation', 'Family']
    ]
]);
```

Whenever possible, you should use the `addDocuments()` method when you want to index multiple documents at the same 
time. There are certain tasks like e.g. updating term frequencies and cleaning up the internal storage state etc. 
which only have to happen once after all the documents are added. So it is more efficient to use `addDocuments()` 
instead of calling `addDocument()` multiple times.

Both of the methods might throw exceptions in case of invalid data (e.g. documents not matching the schema).

### Streaming large JSON files

Passing an array to `addDocuments()` requires the complete dataset to be held in PHP memory. For large JSON files,
you can instead pass a `DocumentSource`. Loupe validates the complete source first and indexes it in a second pass,
so the factory must return a **new iterable every time it is called**. This preserves the all-or-nothing validation
behavior without materializing all documents at once.

Loupe does not depend on a JSON streaming library. The following examples show how to integrate two popular options.
Each yielded document must be decoded as an associative array.

#### JSON Machine

Install [JSON Machine] with Composer:

```console
composer require halaxa/json-machine
```

Configure its decoder to return associative arrays and create a new iterator inside the factory:

```php
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use Loupe\Loupe\Indexing\DocumentSource;

$documents = DocumentSource::fromFactory(
    static fn (): iterable => Items::fromFile('/path/to/movies.json', [
        'decoder' => new ExtJsonDecoder(true),
    ]),
);

$loupe->addDocuments($documents);
```

If the documents are nested below a property such as `results`, add `'pointer' => '/results'` to the options passed
to `Items::fromFile()`.

#### Cerbero JSON Parser

Install [Cerbero JSON Parser] with Composer:

```console
composer require cerbero/json-parser
```

The parser decodes JSON objects as associative arrays by default. Instantiate it inside the factory so each Loupe
pass starts from the beginning of the file:

```php
use Cerbero\JsonParser\JsonParser;
use Loupe\Loupe\Indexing\DocumentSource;

$documents = DocumentSource::fromFactory(
    static fn (): iterable => new JsonParser('/path/to/movies.json'),
);

$loupe->addDocuments($documents);
```

Do not create either parser before the factory and return the same instance from it. Streaming iterators are usually
one-shot, while `DocumentSource` deliberately requires a fresh traversal for validation and indexing.

## Removing documents

To remove documents from the index, you can either remove a single document or batch the removal for
better performance. Whenever possible, you should prefer deleting multiple documents at once over
deleting each document on its own to improve performance and cleanup cost.

You'll need to pass in the id of a document to have it removed from the index.

```php
$loupe->deleteDocument(123);
```

Or you can remove multiple documents at once:

```php
$loupe->deleteDocuments([123, 456]);
```

## Removing all documents

If you need to remove all documents at once and start with a clean slate, there's a method for that:

```php
$loupe->deleteAllDocuments();
```

For schema related logic, read [the dedicated schema docs][Schema].

[Schema]: schema.md
[Cerbero JSON Parser]: https://github.com/cerbero90/json-parser
[JSON Machine]: https://github.com/halaxa/json-machine
