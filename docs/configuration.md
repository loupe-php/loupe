# Configuration

Loupe can be fine-tuned to match your requirements. As with any search engine, Loupe optimizes the documents you index
for efficient retrieval later on. This means, indexing takes rather long compared to searching.
Moreover, Loupe is typo tolerant which is achieved using a State Set Index implementation. Loupe is shipped with 
sane defaults, but you may want to tweak the parameters for your use case.

But let's start with the basic configuration. 

## Document ID

Every document has to have an identifier in Loupe. By default, Loupe expects every document you index to have an `id` 
key. But you can adjust that to your needs: 

```php
$configuration = \Loupe\Loupe\Configuration::create()
    ->withPrimaryKey('uuid')
;
```

## Searchable attributes

By default, Loupe indexes all the attributes of your documents. This makes the search index considerably bigger.
So be sure to configure, which attributes you want to search through later on:

```php
$configuration = \Loupe\Loupe\Configuration::create()
    ->withSearchableAttributes(['firstname', 'lastname'])
;
```

## Filterable attributes

By default, no attribute can be filtered on in Loupe. Any attribute you want to filter for, needs to be defined as 
such before you start indexing. Notice that the attributes can be single values (scalar) but also arrays - Loupe 
does everything for you:

```php
$configuration = \Loupe\Loupe\Configuration::create()
    ->withFilterableAttributes(['departments', 'age'])
;
```

## Sortable attributes

Loupe can order your results by any scalar attribute of your document:

```php
$configuration = \Loupe\Loupe\Configuration::create()
    ->withSortableAttributes(['age', 'lastname'])
;
```

## Tokenization

In order to optimize tokenization for your use case, read [the "Tokenizer" section of the docs](tokenizer.md). These 
are the options:

```php
$configuration = \Loupe\Loupe\Configuration::create()
    ->withMaxQueryTokens(12)
    ->withLanguages(['en', 'fr', 'de'])
;
```


## Minimum length for prefix search

In Loupe - as in MeiliSearch - we follow the philosophy of prefix search. 

Prefix search means that it's not necessary to type a word in its entirety to find documents containing that 
word — you can just type the first few letters. So `huck` would also find `huckleberry`.

Prefix search is only performed on the last word in a search query. Prior words must be typed out fully to get 
accurate results. E.g. `my friend huck` would find documents containing `huckleberry` - `huck is my friend`, however, 
would not.

Searching by prefix (rather than using complete words) has a significant impact on search time. 
The shorter the query term, the more possible matches in the dataset.

That's why you can also configure the minimum length of characters that a term must contain before the prefix search 
kicks in. By default, this is configured to `3`. So searching for `h` would not find `huckleberry` while `huc` would.

You can configure this behavior:

```php
$configuration = \Loupe\Loupe\Configuration::create()
    ->withMinTokenLengthForPrefixSearch(1)
;
```

## Typo tolerance

Loupe is typo tolerant! This is achieved by implementing the algorithm presented in the 2012 research paper "Efficient 
Similarity Search in Very Large String Sets" by Dandy Fenz, Dustin Lange, Astrid Rheinländer, Felix Naumann,
and Ulf Leser from the Hasso Plattner Institute, Potsdam, Germany and Humboldt-Universität zu Berlin, Department of
Computer Science, Berlin, Germany.

The algorithm allows to efficiently search through huge datasets with typos (Levenshtein distance) while keeping the
index size small. [Download the paper and read all the details here][Paper].

Typo tolerance is configured as a sub object of the `Configuration` class:

```php
$typoTolerance = \Loupe\Loupe\Config\TypoTolerance::create();

$configuration = \Loupe\Loupe\Configuration::create()
    ->withTypoTolerance($typoTolerance)
;
```

In the following examples, we're thus only going to look at the `TypoTolerance` method calls.

### Disabling typo tolerance

By default, typo tolerance is enabled, but you can disable typo tolerance entirely. It's as easy as this:

```php
$typoTolerance = \Loupe\Loupe\Config\TypoTolerance::disabled();
```

### Alphabet size and index length

Those are the two major configuration values that affect basically everything in Loupe:

- The index size
- The indexing performance
- The search performance

It's pretty hard to explain the State Set Index algorithm in a few short words but I tried my very best to explain 
some of it in the [Performance](performance.md) section. Best is to read the academic paper
linked. However, one thing to note: You **cannot** get wrong search results no matter what values you configure. Those  
values are basically about the number of potential false-positives that then have to be filtered by 
running the Levenshtein algorithm on all results. The higher the values, the less false-positives. But also the more 
space required for the index.

The alphabet size is configured to `4` by default. The index length to `14`.

```php
$typoTolerance = \Loupe\Loupe\Config\TypoTolerance::create()
    ->withAlphabetSize(5)
    ->withIndexLength(18)
;
```

### Typo thresholds

Usually, the longer the words, the more typos should be tolerated. It makes no sense to tolerate `6` typos for a word 
like `search` as it would mean that `engine` matches as well.

By default, Loupe tolerates `2` typos for words that are `9` or more characters long and `1` typo for `5` to `8` 
character long words. You can configure those thresholds. The key is the threshold and the value represents the 
allowed typos:

```php
$typoTolerance = \Loupe\Loupe\Config\TypoTolerance::create()
    ->withTypoThresholds([
        8 => 2, // 8 or more characters allow for 2 typos
        3 => 1, // 3 - 7 characters, allow one typo
    ])
;
```

### Count a typo at the beginning of the word as two mistakes

Typos at the beginning of a word are not as likely as typos in between words. Thus, Loupe counts a
typo at the first character of a word as two typos by default. You can disable this behavior like so:

```php
$typoTolerance = \Loupe\Loupe\Config\TypoTolerance::create()
    ->withFirstCharTypoCountsDouble(false)
;
```

### Prefix search with typos

By default, Loupe will not allow typos on prefixes. So if you e.g. search for `Huckle`, it will find `Huckleberry` 
but if you search for `Hukcle`, it won't. This is for performance reasons. However, you can enable typo tolerance on 
prefix search. Just be aware that you probably shouldn't do this in case you have tens of thousands of documents:

```php
$typoTolerance = \Loupe\Loupe\Config\TypoTolerance::create()
    ->withEnabledForPrefixSearch(true)
;
```

## Debugging

You may pass a PSR-3 logger to Loupe. For the sake of simplicity, Loupe also ships with a very simple 
`InMemoryLogger` so you don't have to require any special package only to quickly debug internals:

```php
$logger = new \Loupe\Loupe\Logger\InMemoryLogger();

$configuration = \Loupe\Loupe\Configuration::create()
    ->withLogger($logger)
;

print_r($logger->getRecords());
```


[Paper]: https://hpi.de/fileadmin/user_upload/fachgebiete/naumann/publications/PDFs/2012_fenz_efficient.pdf