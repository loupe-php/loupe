# Indexing

## Adding documents

There are two methods to index documents in Loupe. Either you index only one document like so:

```php
$indexResult = $loupe->addDocument([
    'id' => 12,
    'title' => 'Finding Nemo',
    'overview' => 'Nemo, an adventurous young clownfish, …',
    'genres' => ['Animation', 'Family']
]);
```

Or you can index multiple documents:

```php
$indexResult = $loupe->addDocuments([
    'id' => 11,
    'title' => 'Star Wars',
    'overview' => 'Princess Leia is captured and held hostage by the evil Imperial forces …',
    'genres' => ['Adventure', 'Action', 'Science Fiction']
], [
    'id' => 12,
    'title' => 'Finding Nemo',
    'overview' => 'Nemo, an adventurous young clownfish, …',
    'genres' => ['Animation', 'Family']
]);
```

Whenever possible, you should use the `addDocuments()` method when you want to index multiple documents at the same 
time. There are certain tasks like e.g. updating term frequencies and cleaning up the internal storage state etc. 
which only have to happen once after all the documents are added. So it is more efficient to use `addDocuments()` 
instead of calling `addDocument()` multiple times.

Both of the methods return an `IndexResult` which provides the following methods:

* `successfulDocumentsCount()` - contains the number of successfully indexed documents.
* `erroredDocumentsCount()` - contains the number of errored documents.
* `exceptionForDocument(int|string $documentId)` - allows you to check if a specific document ID errored and if so, 
  why. This method returns either `null` (if it was successful) or an exception implementing `LoupeExceptionInterface`.
* `allDocumentExceptions()` - returns all document related exceptions with the document ID as the key and an 
  exception implementing `LoupeExceptionInterface` as value.
* `generalException()` - returns either `null` (if there was no general exception) or an exception implementing 
  `LoupeExceptionInterface`. A general exception is one that could not be linked to a document ID.

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
