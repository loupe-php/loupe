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

You can also exclude documents that match to a given keyword. Use `-` as modifier. You can exclude both, regular keywords
as well as phrases:

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

Note that you can only filter [on attributes that you have defined to be filerable in the configuration][Config].

```php
$searchParameters = \Loupe\Loupe\SearchParameters::create()
    ->withFilter("(departments = 'Backoffice' OR departments = 'Project Management') AND age > 17")
;
```

Loupe can even filter on geo distance! See geo search section for more information.

To make sure you properly escape the filter values, you can use `SearchParameters::escapeFilterValue()`.

## Sort

By default, Loupe sorts your results based on relevance. Relevance is determined using a number of factors such as the
number of matching terms but also the proximity (search for `pink floyd` will make sure documents that contain `pink floyd`
will be ranked higher than `the pink pullover of Floyd`). The relevance attribute is reserved and is called `_relevance`.
You can sort by your own attributes or by multiple ones and specify whether to sort ascending or descending:

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

Note: You cannot go any higher than `1000` documents per page. The higher the value you choose, the slower Loupe gets.

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

## Stop words

When searching, you can define a list of stop words to be ignored by the engine when matching and
ranking search results.

```php
$searchParameters = \Loupe\Loupe\SearchParameters::create()
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
[Restaurant_Fixture]: ./../tests/Functional/IndexData/restaurants.json

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
[Restaurant_Fixture]: ./../tests/Functional/IndexData/locations.json
