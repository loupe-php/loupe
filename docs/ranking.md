# Ranking

Loupe uses a multi-factor ranking system to determine the relevance of search results:

1. Number of matched query terms
2. Proximity of matched terms in the text
3. Ranking of matched attributes

## Best Practices

- Put your most important attributes first in the list of search attributes
- Use the ranking score threshold to filter out low-quality matches
- Consider the proximity factor when writing content - keep related terms close together
- Monitor ranking scores during development to fine-tune your configuration


## Ranking Factors

### 1. Number of Matched Terms

The most important factor is how many of the searched terms are found in the document.

For example, when searching for "quick brown fox":

- A document containing all three words would score `1.0`
- A document with only "quick" and "fox" would score `0.67`
- A document with just "brown" would score `0.33`

### 2. Proximity of Matched Terms

Term proximity measures how close matching terms appear to each other in the document. Terms that are
closer together receive higher scores. Adjacent terms receive a perfect proximity score of `1.0`. As
terms get further apart, the score decays exponentially by a factor of `0.1`.

Example proximity scores:

- "quick brown fox" (adjacent words) would score `1.0`
- "quick ... brown" (5 words apart) would score `0.6`
- "quick ... ... brown" (10 words apart) would score `0.35`

### 3. Attribute Ranking

Some fields are more relevant for search results than others, e.g. the `title` field should usually
be the most important attribute for search. The attribute ranking order reflects this by ranking results
higher if the query terms were found in searchable attributes listed earlier. Each subsequent
attribute's weight is multiplied by `0.8`.

```php
use Loupe\Loupe\Configuration;

$configuration = Configuration::create()
    ->withSearchableAttributes([
        'title',    // weight: 1.0
        'summary',  // weight: 0.8
        'content'   // weight: 0.64
    ]);
```

Note: When using the default `['*']`, all attributes are weighted equally.

## Fine-tuning Results

### Minimum Score Threshold

Filter out low-relevance results by setting a minimum ranking score:

```php
use Loupe\Loupe\SearchParameters;

$params = SearchParameters::create()
    ->withQuery('search terms')
    ->withRankingScoreThreshold(0.5)  // Only keep results scoring 0.5 or higher
;
```

### Attribute Order

Prioritize matches in specific fields by ordering attributes from highest to lowest importance:

```php
use Loupe\Loupe\Configuration;

$configuration = Configuration::create()
    ->withSearchableAttributes([
        'title',     // Highest importance
        'subtitle',  // ↓
        'summary',   // ↓
        'content'    // Lowest importance
    ]);
```

### Debug Ranking Scores

During development, you can inspect ranking scores to understand and tune your search results. If
configured, each result will include a `_rankingScore` field showing its calculated relevance
between `0.0` and `1.0`.

```php
use Loupe\Loupe\SearchParameters;

$params = SearchParameters::create()
    ->withQuery('search terms')
    ->withShowRankingScore(true)
;
```
