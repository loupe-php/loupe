# Schema

Loupe is mainly designed for a reasonable amount of documents and to solve the problem of a better `LIKE '%...%'` 
query efficiently and quickly. Part of this design is that Loupe doesn't require any complex document schema setup. 
That, however, doesn't mean Loupe does not require a document schema. Quite the contrary, it's just derived from the 
documents you index.

Let's look at a movie example:

```php
$loupe->addDocument([
    'id' => 12,
    'title' => 'Finding Nemo',
    'overview' => 'Nemo, an adventurous young clownfish, …',
    'genres' => ['Animation', 'Family']
]);
```

By indexing this first document, Loupe will derive the following schema from that document:

```yaml
title: string
overview: string
genres: array<string>
```

Adding our second document, is now going to be validated against the previously defined schema:

```php
$loupe->addDocument([
    'id' => 11,
    'title' => 'Star Wars',
    'overview' => 'Princess Leia is captured and held hostage by the evil Imperial forces …',
    'genres' => ['Adventure', 'Action', 'Science Fiction']
]);
```

This works, because `Star Wars` is a `string`, `overview` as well and the `genres` are also an array of `string`s.

## Non-matching types

If, however, you now want to index a second document that does not fulfill this schema, you will get an 
`InvalidDocumentException`.:

```php
$loupe->addDocument([
    'id' => 11,
    'title' => 'Star Wars',
    'overview' => [], // Not allowed, must be a string
    'genres' => [2, 16] // Not allowed, must be an array of strings
]);
```

## Nullable attributes

In Loupe, every attribute is nullable. If you want to ensure that e.g. all your documents have a `title` attribute, 
you need to ensure this in your application. For Loupe, this would be a valid document:

```php
$loupe->addDocument([
    'id' => 404,
    'title' => null,
    'overview' => null,
    'genres' => null
]);
```

## Omitting an attribute

Because all the attributes are `nullable` by default, you can also omit attributes. They will be assigned `null` 
automatically internally.

This document is perfectly valid, `title`, `overview` and `genres` will get assigned a `null` value:

```php
$loupe->addDocument([
    'id' => 404
]);
```

## Adding a new attribute

Adding a new attribute is valid, all previously indexed documents will get `null` assigned for those attributes. To 
stick with our example, let's add a `release_date`:

```php
$loupe->addDocument([
    'id' => 11,
    'title' => 'Star Wars',
    'overview' => 'Princess Leia is captured and held hostage by the evil Imperial forces …',
    'genres' => ['Adventure', 'Action', 'Science Fiction'],
    'release_date' => 233366400
]);
```

Our schema is now updated:

```yaml
title: string
overview: string
genres: array<string>
release_date: number
```

Our `Finding Nemo` document ID 12 now automatically got `release_date = null` assigned which means you will **NOT** 
find it when filtering e.g. with `release_date >= 0` because `null !== 0`. If you want to include it, you 
will have to combine filters using `release_date >= 0 AND release_date IS NULL`.

## Narrowing schema

Let's imagine you indexed your **first** document like this:

```php
$loupe->addDocument([
    'id' => 12,
    'title' => 'Finding Nemo',
    'overview' => null,
    'genres' => [],
]);
```

This would result in the following schema internally:

```yaml
title: string
overview: null
genres: array
```

What happens if we want to index our Star Wars movie now?  After all, `overview` is not `null` and `genres` is now an 
array of strings. This is possible, as we're narrowing down the types. 

```php
$loupe->addDocument([
    'id' => 11,
    'title' => 'Star Wars',
    'overview' => 'Princess Leia is captured and held hostage by the evil Imperial forces …',
    'genres' => ['Adventure', 'Action', 'Science Fiction'],
]);
```

After this second document, our schema will be updated to

```yaml
title: string
overview: string
genres: array<string>
```
