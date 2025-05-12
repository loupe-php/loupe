# Browsing

Browsing Loupe is achieved by using the `BrowseParameters` object. That one is passed on to Loupe and you get a 
`BrowseResult` back:

```php
$browseParameters = \Loupe\Loupe\BrowseParameters::create();
$results = $loupe->browse($browseParameters);

print_r($results->toArray());
```

The browse API is here to help you walk over all the documents in your index e.g. for CSV exports etc.
The difference to the search API is that there is no maximum document hits (pagination limits still apply) and there are no sorting options. You can use

* document filtering using `query` and `filter`
* consequently configure to which attributes `query` should apply using `attributesToSearchOn`
* define which attributes you want to retrieve using `attributesToRetrieve`
* use pagination, either with `limit` and `offset` or `page` and `hitsPerPage`.

