# Searching

Searching Loupe is achieved by using the `SearchParameters` object. That one is passed on to Loupe and you get a 
`SearchResult` back:

```php
$searchParameters = \Loupe\Loupe\SearchParameters::create();
$results = $loupe->search($searchParameters);

print_r($results->toArray());
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

You can also exclude documents that match to a given keyword. Use `-` as modifier. You can exclude both, regular keywords as well as phrases:

```php
$searchParameters = \Loupe\Loupe\SearchParameters::create()
    ->withQuery('This but -"not this" or -this')
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

## Attributes to search on

By default, Loupe searches all [configured `searchable attributes`][Config] but you can limit your query to only a 
subset of those:

```php
$searchParameters = \Loupe\Loupe\SearchParameters::create()
    ->withAttributesToSearchOn(['firstname'])
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
* `IN ()`
* `NOT IN ()`
* `IS NULL` (takes no value)
* `IS NOT NULL` (takes no value)
* `IS EMPTY` (takes no value, empty values are `''` and `[]`)
* `IS NOT EMPTY` (takes no value, empty values are `''` and `[]`)
* `BETWEEN <float> AND <float>`
* `NOT BETWEEN <float> AND <float>`

Note that you can only filter [on attributes that you have defined to be filerable in the configuration][Config].

```php
$searchParameters = \Loupe\Loupe\SearchParameters::create()
    ->withFilter("(departments = 'Backoffice' OR departments = 'Project Management') AND age > 17")
;
```
Loupe can even filter on geo distance! See geo search section for more information.

To make sure you properly escape the filter values, you can use `SearchParameters::escapeFilterValue()`.

### Filtering on array attributes

Note the difference of filter handling when working with array attributes (or as Loupe calls them, multi attributes).
Imagine the following documents:

```json
[
    {
        "id": 13,
        "ratings": [2, 3, 4]
    },
    {
        "id": 42,
        "ratings": [1, 5]
    }
]
```

If you filter for `ratings >= 2 AND ratings <= 4`, you will get **both** documents. That is because `ratings` is a multi
attribute and all of its values are evaluated individually. If you want both conditions to apply, use the `BETWEEN` 
operator: `ratings BETWEEN 2 AND 4`.

## Search with facets

Yes, you read that right, Loupe even supports facets! Facets are supported on all filterable attributes, so make sure you have defined those first:

```php
$configuration = \Loupe\Loupe\Configuration::create()
    ->withFilterableAttributes(['departments', 'age'])
;
```

You can then ask Loupe to create facets for the attributes you're interested in:

```php
$searchParameters = SearchParameters::create()
    ->withQuery('...')
    ->withFilter('...')
    ->withFacets(['departments', 'age'])
;
```

The result will contain all documents matching the query and the filter. It also returns two fields you can use to create a faceted search interface, `facetDistribution` and `facetStats`:

```php
$results = [
    'hits' => [...],
    'facetDistribution' => [
        'departments' => [
            'Backoffice' => 2,
            'Development' => 2,
            'Engineering' => 2,
            'Facility-Management' => 1,
            'Project Management' => 1,
        ],
    ],
    'facetStats' => [
        'age' => [
            'min' => 18.0,
            'max' => 96.0,
        ],
    ],
];
```

`facetDistribution` lists all facets present in your search results, along with the number of documents returned for each facet for attributes containing string values.

`facetStats` contains the highest and lowest values for all facets containing numeric values.

## Sort

By default, Loupe sorts your results based on relevance. Relevance is determined using a number of factors such as the
number of matching terms but also their proximity in the document. The relevance attribute is reserved and is
called `_relevance`. See [Ranking](./ranking.md) for details about how relevance is calculated for each result
and how you can influence the ranking from the configuration.

You can sort by your own attributes or by multiple ones and specify whether to sort ascending or descending.
Note that you can only sort [on attributes that you have defined to be sortable in the configuration][Config].

```php
$searchParameters = \Loupe\Loupe\SearchParameters::create()
    ->withSort(['lastname:asc', '_relevance:desc']) // First by lastname alphabetically and then by best match
;
```

In case you are interested in the ranking score of the relevance sorting, you can ask Loupe to add the score to the 
search result hits using

```php
$searchParameters = \Loupe\Loupe\SearchParameters::create()
    ->withShowRankingScore(true)
;
```

In this case, every hit will have an additional `_rankingScore` attribute with a value between `0.0` and `1.0`.

You can also limit the search results to a `rankingScoreThreshold` between `0.0` and `1.0`:

```php
$searchParameters = \Loupe\Loupe\SearchParameters::create()
    ->withRankingScoreThreshold(0.8)
;
```

### Sorting on array attributes

Sometimes you want to sort by an array attribute, in which case, you need to use an aggregate function. Let's say you
have two documents with an array of numbers:

```php
[
    ['id' => 1, ['numbers' => [2, 3, 4, 5]]],
    ['id' => 2, ['numbers' => [1, 3, 4, 5]]],
]
```

In order to be able to properly sort by those, you need to tell Loupe which one of those to use. Let's say you want
to sort by the lowest of them:

```php
$searchParameters = \Loupe\Loupe\SearchParameters::create()
    ->withSort(['min(numbers):asc'])
;
```

Also note that Loupe will apply filters as well. So in case you used the sorting in combination with the filter like so:

```php
$searchParameters = \Loupe\Loupe\SearchParameters::create()
    ->withFilter('numbers >= 2 AND numbers <= 4')
    ->withSort(['min(numbers):asc'])
;
```

Loupe will return document ID 1 before document ID 2 even though document ID 2 has the lowest number `1` as attribute value.
But as you are not interested in these values according to your filter, Loupe will order accordingly.

Currently, you can use the following aggregate functions:

* `min(<attribute>)`
* `max(<attribute>)`

## Pagination

When searching Loupe, it will always return the current `page`, `totalPages` as well as `totalHits` in its search 
results. You can navigate through the pages by specifying the desired page in the search parameters:

```php
$searchParameters = \Loupe\Loupe\SearchParameters::create()
    ->withPage(3);
```

By default, Loupe returns `20` results per page, but you can configure this as well:

```php
$searchParameters = \Loupe\Loupe\SearchParameters::create()
    ->withHitsPerPage(50);
```

You can also work with offset and limit but be aware that if either `withPage()` or `withHitsPerPage()` is given, those
take precedence:

```php
$searchParameters = \Loupe\Loupe\SearchParameters::create()
    ->withOffset(10)
    ->withLimit(100)
;
```

Note: You cannot go any higher than `1000` documents per page. The higher the value you choose, the slower Loupe gets.

## Grouping / Distinct attribute

Sometimes you may want to group search results by a distinct attribute. This might be useful for cases where you want to reduce the final result set for a user, giving them an easier to browse catalog. If you e.g. index all variants of a certain sneaker (same model but exists in red, blue, green, size 10, 11, 12, etc.), you may want to list it only once in the result set, and it doesn't matter which of those variants. 

```json
[
    {
        "id": "sku-123-red",
        "product_id": 123,
        "color": "red"
    },
    {
        "id": "sku-123-blue",
        "product_id": 123,
        "color": "blue"
    }
]
```

You can do this by using the attribute they have in common. Loupe will then only return one match per `product_id`:

```php
$searchParameters = \Loupe\Loupe\SearchParameters::create()
    ->withDistinct('product_id')
;
```

## Term highlighting

You can enable term highlighting by telling Loupe, which attributes you're interested in:

```php
$searchParameters = \Loupe\Loupe\SearchParameters::create()
    ->withAttributesToHighlight(['title', 'overview'])
```

Your result hits will then contain a `_formatted` key where you'll find the matches embedded in `<em>` and `</em>` tags.
The opening and closing tags can also be configured, in case you need something different from `<em>` and `</em>`. For this,
use the second and third parameters like so:

```php
$searchParameters = \Loupe\Loupe\SearchParameters::create()
    ->withAttributesToHighlight(['title', 'overview'], '<mark>', '</mark>')
```

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

## Context cropping

Loupe can crop selected attributes to a certain length around the search terms. The is useful to
show as much context as possible around matched words when displaying results.

```php
$searchParameters = \Loupe\Loupe\SearchParameters::create()
    ->withAttributesToCrop(['title', 'summary']);
```

The result of a cropped attribute will look like this:

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
                'overview' => 'An assassin is shot by her ruthless … members of their assassination circle …',
            ],
        ],
    ],
];
```

The default crop length is 50 characters. You can define a different crop length by passing in an
integer value.

```php
$searchParameters = \Loupe\Loupe\SearchParameters::create()
    ->withAttributesToCrop(['title', 'summary'], cropLength: 30);
```

Optionally, define a custom crop length for each attribute by passing in an array of lengths keyed by attribute name.

```php
$searchParameters = \Loupe\Loupe\SearchParameters::create()
    ->withAttributesToCrop(['title' => 20, 'summary' => 40]);
```

Crop boundaries are marked with an ellipsis `…` character by default. You can change this by passing in a custom marker.

```php
$searchParameters = \Loupe\Loupe\SearchParameters::create()
    ->withAttributesToCrop(['title', 'summary'], cropMarker: '∞');
```

## Stop words

When configuring Loupe, you can define a list of stop words to be ignored by the engine when matching and
ranking search results.

```php
$configuration = \Loupe\Loupe\Configuration::create()
    ->withStopWords(['a', 'by', 'the']);
```

## Caching

Loupe does not ship with built-in caching, so you are free to choose the caching mechanism of your choice. However,
you can identify a search parameter combination using the `getHash()` method:

```php
$hash = \Loupe\Loupe\SearchParameters::create()
    ->getHash();
```

## Geo search

Yes, Loupe also supports geo search! In contrast to MeiliSearch, however, Loupe does not need any special `_geo` 
attribute, but you can filter and sort by multiple geo attributes. The only requirement is that your attribute 
represents the following format:

```php
<?php

$myGeoAttribute = [
    'lat' => 45.4777599,
    'lng' => 9.1967508,
];
```

If your attribute is of this format, and you define it as being filterable and/or sortable, Loupe can both, filter and 
sort on it. Let's take an example:

First, we have to index some restaurants. See [the `restaurants.json` from the test fixtures][Restaurant_Fixture].
Then we need to define the `_location` attribute as being `filterable` and `sortable`:

```php
$configuration = \Loupe\Loupe\Configuration::create()
    ->withFilterableAttributes(['location'])
    ->withSortableAttributes(['location'])
;
```

We can then filter for all the restaurants within 2km from `45.472735, 9.184019`. For this, we use the `_geoRadius()`
filter. It takes the attribute name, latitude and longitude as  parameters. The fourth parameter 
defines the distance in meters.
Then, we also want to sort them by their distance from `45.472735, 9.184019` so that the closest is listed first. 
For that, we can use the `_geoPoint()` sorter.
We also want to know the distance, so we select the special `_geoDistance(location)` attribute as well:

```php
$searchParameters = SearchParameters::create()
    ->withAttributesToRetrieve(['id', 'name', 'location', '_geoDistance(location)'])
    ->withFilter('_geoRadius(location, 45.472735, 9.184019, 2000)')
    ->withSort(['_geoPoint(location, 45.472735, 9.184019):asc'])
;
```

This will result in exactly what we want:

```php
$results = [
    'hits' => [
        [
            'id' => 1,
            'name' => "Nàpiz' Milano",
            'location' => [
                'lat' => 45.4777599,
                'lng' => 9.1967508,
            ],
            '_geoDistance(location)' => 1139,
        ],
        [
            'id' => 3,
            'name' => 'Artico Gelateria Tradizionale',
            'location' => [
                'lat' => 45.4632046,
                'lng' => 9.1719421,
            ],
            '_geoDistance(location)' => 1418,
        ],
    ],
];
```

[Config]: configuration.md
[Restaurant_Fixture]: ./../tests/Fixtures/Data/restaurants.json

Additional to a query based on distance we can also search for locations inside a bounding box.
In this example we have 4 documents and 3 with geo coordinates (New York, London, Vienna).
We create a bounding box filter which spans from Dublin to Athens which then only matches our documents in London and Vienna.

Keep in mind that the order of the arguments is important.
The `_geoBoundingBox` expects `attributeName`, `north` (top), `east` (right), `south` (bottom), `west` (left).
In this specific example, `top` is the latitude of Dublin,`right` is the longitude of Athens,`bottom` is the latitude of Athens and`left` equals Dublin's longitude.

```php
$searchParameters = SearchParameters::create()
    ->withAttributesToRetrieve(['id', 'name', 'location'])
    ->withFilter('_geoBoundingBox(location, 53.3498, 23.7275, 37.9838, -6.2603)')
;
```

This is going to be your result:

```php
$results = [
    'hits' => [
        [
            'id' => '2',
            'title' => 'London',
            'location' => [
                'lat' => 51.5074,
                'lng' => -0.1278,
            ],
        ],
        [
            'id' => '3',
            'title' => 'Vienna',
            'location' => [
                'lat' => 48.2082,
                'lng' => 16.3738,
            ],
        ],
    ],
    'query' => '',
    'hitsPerPage' => 20,
    'page' => 1,
    'totalPages' => 1,
    'totalHits' => 2,
];
```

[Config]: configuration.md
[Restaurant_Fixture]: ./../tests/Fixtures/Data/locations.json
