# Tokenizer

When you index and when you search Loupe, the document (when indexing) or the user query (when searching) is analyzed 
and split into tokens or what you would call "words".  In languages which use the Latin, Cyrillic, and Arabic 
alphabets, as well as other scripts of Europe and West Asia, the word divider is a blank space, or whitespace. But 
there are of course many languages, especially in Asia and Africa, where words are usually written without word 
separation. Luckily, in PHP we can use the `IntlBreakIterator` to help us with that. A “break iterator” is an ICU 
object that exposes methods for locating boundaries in text (e.g. word or sentence boundaries) so it's the perfect 
match for our use case.

However, in order to split a string into words, we have to first try to determine the language it was written in. 
After all, we have to apply the right rules to the right text. This is not part of PHP's `intl` extension, so we have 
to do this ourselves. Loupe currently uses the excellent [`nitotm/efficient-language-detector`][Language_Detector] 
library to help us with that. However, there are a few things you need to know about language detection.

## Reliability

Language detection works using the [N-Gram model][N_Gram]. Basically, we train the language detector with data, 
extract common N-Grams and use them to try to identify the similarity to some other string. In order to have a 
reliable detection, we need a minimum amount of N-Grams as well as a reliable percentage of similarity. An `N-Gram` 
is a string of `4` bytes.

For details, head to [`nitotm/efficient-language-detector`][Language_Detector] which is also the place where you should 
contribute if your desired language is missing.

The minimum amount of N-Grams to allow for a reliable detection is `3`. If we'd analyze `Star Wars`, we would not 
have enough data to determine the language reliably. This is because `Star` as well as `Wars` are each `4` bytes of 
data forming their own N-Grams. So we only end up having `2` N-Grams which is not enough data for a reliable detection.
If, however, we'd analyze `Star Wars is my favorite movie`, we'd end up with `9` N-Grams and a reliable detection is 
possible.

## Document vs query language detection

There's a difference between language detection in a query (when searching) or within a document (when indexing). 
This is because if you search for `Star Wars`, there's only so much data. We cannot be smart about it. For documents,
however, we likely have more data. Maybe your document looks like this:

```json
{
    "id": 11,
    "title": "Star Wars",
    "overview": "Princess Leia is captured and held hostage by the evil Imperial forces..."
}
```

So what we can do here is, instead of only looking at every attribute individually, we can also try to detect the 
language in all of the `searchableAttributes` of the document. So if we detect that `overview` is probably in 
English, we can assume `title` is too. Loupe does exactly that but **only if** `title` by itself could not be 
detected reliably. To stick with our example from before: If the `title` were `Star Wars is my favorite movie` we 
would not touch it. That means that you can also mix languages within a document and Loupe will detect them 
individually as long as there is enough data for a reliable detection.

If no language can be detected at all, strings are tokenized using the `locale_get_default()`. At the PHP 
initialization this value is set to 'intl.default_locale' value from your `php.ini` if that value exists or from ICU's 
function `uloc_getDefault()`.

## Stemming

Stemming is the process of [reducing words to their word stem, base or root form][Stemming]. Think of reducing `fishing`,
`fished`, and `fisher` to the stem `fish`. Loupe does this by default using [`wamania/php-stemmer`][Stemmer]. The 
goal of stemming is straightforward: We want to optimize the search results so that when your users search for e.g. 
a past tense of a word or a plural, they would also find the documents containing information that was written in 
present tense or singular form etc.

Just like for optimal tokenization, for optimal stemming we need proper language detection as well. Keep this in 
mind because even though `Wars` in `Star Wars` would get correctly stemmed to `war` in English, this only works when 
the English language can be detected reliably - which will not work for `Star Wars` if it were standalone.

## Configuration options in Loupe

To improve the language detection during both, the indexing and the querying process, you can ask Loupe to only 
consider a subset of supported languages. If, for example, you know that all your documents are either written in 
German or French, then you can ensure that Loupe will only consider those two languages for detection:

```php
$configuration = \Loupe\Loupe\Configuration::create()
    ->withLanguages(['de', 'fr']) // Language codes must be ISO 639-1
```

In order to make sure that searching is fast and efficient, there is a limit as to how many tokens will be
considered when you query Loupe. By default, this value is configured to `10` but you may adjust this to your needs. The
higher the value, the higher the chance that the process takes too long or uses up too many resources.

```php
$configuration = \Loupe\Loupe\Configuration::create()
    ->withMaxQueryTokens(12)
```

[Language_Detector]: https://github.com/nitotm/efficient-language-detector
[Stemmer]: https://github.com/wamania/php-stemmer
[N_Gram]: https://en.wikipedia.org/wiki/Word_n-gram_language_model
[Stemming]: https://en.wikipedia.org/wiki/Stemming