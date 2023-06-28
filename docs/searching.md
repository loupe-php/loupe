# Searching

Searching Loupe is achieved by using the `SearchParameters` object. That one is passed on to Loupe and you get the 
result back:

```php
$searchParameters = \Loupe\Loupe\SearchParameters::create();
$results = $loupe->search($searchParameters);

print_r($results);
```

## Query

The one thing you'd expect from a search engine is to search for a query:

```php
$searchParameters = \Loupe\Loupe\SearchParameters::create()
    ->withQuery('Hello World')
;
```

This will return all documents matching `Hello` or `World` while considering the [typo tolerance settings][Config].

Loupe also supports phrase search, so if you want to query for documents containing exactly `Hello World`, you'll 
have to use `"` to encapsulate your query:

```php
$searchParameters = \Loupe\Loupe\SearchParameters::create()
    ->withQuery('"Hello World"')
;
```

Hint: Note that your query is stripped if it's very long. See the section about [maximum query tokens in the 
configuration settings][Config].

## Attributes to receive

By default, Loupe returns all the attributes of the documents you've indexed. If you are interested in only a subset 
of them, you can specify which ones you want to retrieve:

```php
$searchParameters = \Loupe\Loupe\SearchParameters::create()
    ->withAttributesToRetrieve(['id', 'firstname'])
;
```

## Filter

Loupe provides a powerful way to filter your documents. Know SQL? Then you'll have absolutely no issues filtering 
with Loupe either. You can combine your filters with `AND` and `OR`, nest them the way you want and use the 
following operators:

* `=`
* `>`
* `<`
* `>=`
* `<=`

Note that you can only filter [on attributes that you have defined to be filerable in the configuration][Config].

```php
$searchParameters = \Loupe\Loupe\SearchParameters::create()
    ->withFilter("(departments = 'Backoffice' OR departments = 'Project Management') AND age > 17")
;
```

Loupe can even filter on geo distance! See geo search section for more information.

## Sort

By default, Loupe sorts your results based on relevance. Relevance is determined using a TF-IDF algorithm combined 
with cosine similarity. The relevance attribute is reserved and is called `_relevance`. You can sort by your own 
attributes or by multiple ones and specify whether to sort ascending or descending:

Note that you can only sort [on attributes that you have defined to be sortable in the configuration][Config].

```php
$searchParameters = \Loupe\Loupe\SearchParameters::create()
    ->withSort(['lastname:asc', '_relevance:desc']) // First by lastname alphabetically and then by best match
;
```

## Pagination

When searching Loupe, it will always return the current `page`, `totalPages` as well as `totalHits` in its search 
results. You can navigate through the pages by specifying the desired page in the search parameters:

```php
$searchParameters = \Loupe\Loupe\SearchParameters::create()
    ->withPage(3);
```

By default, Loupe returns `20` results per page but you can configure this as well:

```php
$searchParameters = \Loupe\Loupe\SearchParameters::create()
    ->withHitsPerPage(50);
```


## Term highlighting

You can enable term highlighting by telling Loupe, which attributes you're interested in:

```php
$searchParameters = \Loupe\Loupe\SearchParameters::create()
    ->withAttributesToHighlight(['title', 'overview'])
```

Your result hits will then contain a `_formatted` key where you'll find the matches embedded in `<em>` and `</em>` tags.
This is how this could look like when having searched for `assassin`:

```php
$results = [
    'hits' => [
        [
            'id' => 24,
            'title' => 'Kill Bill: Vol. 1',
            'overview' => 'An assassin is shot by her ruthless employer, Bill, and other members of their assassination circle – but she lives to plot her vengeance.',
            '_formatted' => [
                'id' => 24,
                'title' => 'Kill Bill: Vol. 1',
                'overview' => 'An <em>assassin</em> is shot by her ruthless employer, Bill, and other members of their <em>assassination</em> circle – but she lives to plot her vengeance.',
            ],
        ],
    ],
];
```

If you need to somehow highlight things differently, you can also ask Loupe to return the match positions (or even 
combine with `withAttributesToHighlight()` if you need both):

```php
$searchParameters = \Loupe\Loupe\SearchParameters::create()
    ->withShowMatchesPosition(true);
```

In this case, the result would look something like this:

```php
$results = [
    'hits' => [
        [
            'id' => 24,
            'title' => 'Kill Bill: Vol. 1',
            'overview' => 'An assassin is shot by her ruthless employer, Bill, and other members of their assassination circle – but she lives to plot her vengeance.',
            '_matchesPosition' => [
                'overview' => [
                    [
                        'start' => 3,
                        'length' => 8,
                    ],
                    [
                        'start' => 79,
                        'length' => 13,
                    ],
                ],
            ],
        ],
    ],
];
```

## Geo search

Yes, Loupe also supports geo search! Just like MeiliSearch does, Loupe also works with a special `_geo` attribute.
If your document contains that and you define it as being filterable and/or sortable, Loupe can both, filter and 
sort on it. Let's take an example:

First, we have to index some restaurants. See [the `restaurants.json` from the test fixtures][Restaurant_Fixture].
Then we need to define the `_geo` attribute as being `filterable` and `sortable`:

```php
$configuration = \Loupe\Loupe\Configuration::create()
    ->withFilterableAttributes(['_geo'])
    ->withSortableAttributes(['_geo'])
;
```

We can then filter for all the restaurants within 2km from `45.472735, 9.184019`. For this, we use the `_geoRadius()`
filter. It takes the latitude and longitude as first and second parameters. The third parameter defines the distance 
in meters.
Then, we also want to sort them by their distance from `45.472735, 9.184019` so that the closest is listed first. 
For that, we can use the `_geoPoint()` sorter.
We also want to know the distance, so we select the special `_geoDistance` attribute as well:

```php
$searchParameters = SearchParameters::create()
    ->withAttributesToRetrieve(['id', 'name', '_geo', '_geoDistance'])
    ->withFilter('_geoRadius(45.472735, 9.184019, 2000)')
    ->withSort(['_geoPoint(45.472735, 9.184019):asc'])
;
```

This will result in exactly what we want:

```php
$results = [
    'hits' => [
        [
            'id' => 1,
            'name' => "Nàpiz' Milano",
            '_geo' => [
                'lat' => 45.4777599,
                'lng' => 9.1967508,
            ],
            '_geoDistance' => 1139,
        ],
        [
            'id' => 3,
            'name' => 'Artico Gelateria Tradizionale',
            '_geo' => [
                'lat' => 45.4632046,
                'lng' => 9.1719421,
            ],
            '_geoDistance' => 1418,
        ],
    ],
];
```

[Config]: configuration.md
[Restaurant_Fixture]: ./../tests/Functional/IndexData/restaurants.json