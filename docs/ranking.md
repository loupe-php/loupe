# Ranking

Loupe uses a multi-factor ranking system to determine the relevance of search results:

1. Number of matched terms
2. Proximity of matched terms
3. Ranking of matched attributes

## Best Practices

1. Put your most important attributes first in the list of search attributes
2. Use the ranking score threshold to filter out low-quality matches
3. Consider the proximity factor when writing content - keep related terms close together
4. Monitor ranking scores during development to fine-tune your configuration


## Ranking Factors

### 1. Number of Matched Terms

The most important factor is how many of the searched terms are found in the document.

For example, if searching for "quick brown fox":

- A document containing all three words would score `1.0`
- A document with only "quick" and "fox" would score `0.67`
- A document with just "brown" would score `0.33`

### 2. Proximity of Matched Terms

The proximity factor measures how close the matching terms are to each other in the document. Terms
that appear closer together result in higher relevance scores. Adjacent terms (words next to each
other) receive a perfect proximity score of `1.0`. As terms get further apart, the score decays
exponentially by a factor of `0.1`.

Example proximity scores:

- "quick brown fox" (adjacent words) would score `1.0`
- "quick ... brown" (5 words apart) would score `0.6`
- "quick ... ... brown" (10 words apart) would score `0.35`

### 3. Attribute Ranking

You can influence ranking by ordering attributes in the `searchableAttributes()` configuration.
Attributes listed earlier have higher importance in ranking. Each subsequent attribute's weight is
multiplied by `0.8`. When using the default setting of `['*']`, no ranking is applied and all
attributes are weighed equally.

```php
use Loupe\Loupe\Configuration;

$configuration = Configuration::create()
    ->withSearchableAttributes([
        'title',    // weight: 1.0
        'summary',  // weight: 0.8
        'content'   // weight: 0.64
    ]);
```

## Controlling Relevance

### Minimum Score Threshold

You can filter out low-relevance results by setting a minimum ranking score threshold.

```php
use Loupe\Loupe\SearchParameters;

$params = SearchParameters::create()
    ->withQuery('search terms')
    ->withRankingScoreThreshold(0.5) // Only return results with score >= 0.5
;

```

### Attribute Priority

To prioritize matches in certain fields, order your searchable attributes from most to least
important. The order will automatically apply decreasing weights to each attribute.

```php
use Loupe\Loupe\Configuration;

$configuration = Configuration::create()
    ->withSearchableAttributes([
        'title',     // Highest priority
        'subtitle',
        'summary',
        'content'    // Lowest priority
    ]);
```

## Viewing Ranking Scores

During development, you can view the ranking scores to understand how your documents are being
ranked. If configured, each result will include a `_rankingScore` field showing its calculated
relevance between `0.0` and `1.0`.

```php
use Loupe\Loupe\SearchParameters;

$params SearchParameters::create()
    ->withQuery('search terms')
    ->withShowRankingScore(true)
;
```
