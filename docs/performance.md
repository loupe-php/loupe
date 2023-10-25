# Performance

Loupe is fast - very fast! Why is this? And what can you do to make it even faster? This chapter is here to document 
some of the internals and to give you ideas on which parameters you can change in order to adjust the performance to 
your needs.

## Basics of search engines

At their core, all search engines basically work the same. When indexing, they split documents into terms and store 
them in such a way that they can be queried efficiently. Typo tolerance is usually achieved by using some sort of 
Levenshtein automaton. Basically, in order to know whether `Sawyer` can match `Huckleberry` with an edit 
distance of `2`, you don't need to calculate the distance between those words entirely. The actual distance between 
those two words is `9` so it would not match `2`. However, why bothering about the `9`? If you were to look at the 
characters step by step, `Sa` would still potentially match `Huckleberry` (if after `Sa` the letters `ckleberry` 
were to follow) but as soon as you reach `Saw`, there is no way you can ever end with a distance less or equal to 
`2` which means you can early abort here. Moreover, if the next word to compare against `Huckleberry` were for 
example `Sawdust`, then you can remember that `Saw` could never lead to a match so you don't even have to compare 
this word anymore as it shares the same prefix.

Prefix! That's exactly the term we're looking for. Most search engines organize their terms in some sort of prefix 
tree. So if we would index `Sawyer` and `Sawdust`, this could look something like this:

* S
  * a
    * w
      * y
        * e
          * r
      * d
        * u
          * s
            * t

As you can see, this allows search engines to **prune** entire parts of a tree. In our case, Anything underneath 
`Saw` can be pruned because it will never match `Huckleberry`. This data structure is also called a "trie" and there 
are programming languages that support data structures like this natively. Also, modern search engines save this 
structure in memory so looking up matches is extremely fast.

There's of course more to an automaton than just this part (backtracking etc.). You may want to search the 
Internet for more information in case you are interested in details.

PHP doesn't have any of those specialized data structures and Loupe is designed to work with an SQLite database and 
not in memory. So how can we still achieve good performance with typo tolerance?

Enter the State Set Index. 

## Adjust the State Set Index settings

So we've just learned that basically, modern search engines that can rely on memory and specialized data structures 
can compare query terms with indexed terms very quickly and efficiently. Loupe, however, does not have those tools. 

What we do is we apply the ideas of a Levenshtein automaton (a nondeterministic finite automaton) to calculate a 
state of a given term when indexing. When searching, we then calculate all the possible states that could be reached 
with a given edit distance and match those up. Pre-calculating all possible states would result in an enormous 
amount of possibilities which of course would not work. Hence, we limit the "alphabet" we work with. So instead of 
working with the real alphabet (UTF-8), we work with a reduced alphabet of numbers. Sounds complex? It sure is but 
it's also genius! Let's look at this with an example.

So we want to index our `Huckleberry` term again and we want it to match `Guckleberry` when we configure the allowed 
edit distance of `2` as in this case, the distance is just `1`.
Again, if we were to calculate all possibilities of what could be done with `Guckleberry` and an edit distance of 
`2`, they are virtually endless. It could be `Guckleber`, `Gaakleberry`, `Puckleberro`, `Huüklebérry` and so on. All 
the UTF-8 characters there are times `2` mistakes. An endless amount of possibilities. So what we do now, is instead of 
working with an UTF-8 alphabet, we assign a number to every letter and reduce this alphabet. So let's say we only 
want an alphabet of `4` digits because that would bring down the possible states to a manageable set! We're lucky 
because in UTF-8 every character has a code point so we can get a number between `1` and `4` for every character like so:

```php
$number = (mb_ord($char, 'UTF-8') % 4) + 1;
```

So let's analyze our search term `Guckleberry`:  `G` would be `4`, `u` would be `2`, `c` would be `4`, `k` would be 
`4` as well - hey, stop! There are already `3` letters that are assigned `4` so there are going to 
be many duplicates now, right? Yes, exactly! That's the whole point! By reducing the alphabet, we can reduce the 
possibilities to search for. But the downside is that this will lead to duplicates when searching because searching 
for `Guckleberry` would be the same as searching for `keGclurubru`. Not a word that makes any sense, but it has the 
exact same meaning when translated to an alphabet of `4` as `Guckleberry`.

This means that we still need to apply a real Levenshtein function on the results in order to filter out 
false-positives. But at the same time we can also filter out an **enormous amount** of terms that will never match with 
a relatively cheap algorithm!

Now you can make it even a bit faster by not analyzing the entire query of `Guckleberry` but only - say - the first 
`5` characters. This is what's called the "index length". Again, this will make the calculation of possible matching 
states quicker but it will also result in more false-positives.

Both, the "alphabet size" and the "index length" affect the size of the stored state set and the number of 
false-positives that then need to be filtered again using a Levenshtein function. So the higher those values, the 
less false-positives you will have. However, generating the possible matching states when querying will also be more 
time-consuming.

Loupe ships with a decent default configuration that is also recommended by the [research paper introducing the 
State Set Index Algorithm][Paper] (alphabet size set to `4`, index length to `14`) which you should totally read in 
case you are interested in details.

You can adjust the default configuration like so:

```php
$typoTolerance = \Loupe\Loupe\Config\TypoTolerance::create()
    ->withAlphabetSize(5)
    ->withIndexLength(18)
;
$configuration = \Loupe\Loupe\Configuration::create()
    ->withTypoTolerance($typoTolerance)
;
```

## Limit the languages to detect

You can read more about what the tokenizer does in the [respective docs](tokenizer.md) but basically, if you know 
that your documents are always in e.g. English and German, always make sure to configure Loupe for those languages. 
In order for Loupe to detect the languages, it has to load a set of N-Grams into memory and if you load the entire 
supported language set, this is quite an amount. You can limit the languages Loupe should detect like so:

```php
$configuration = \Loupe\Loupe\Configuration::create()
    ->withLanguages(['en', 'de'])
;
```

## Configure the searchable attributes

Do not forget to configure the searchable attributes. By default, Loupe will index all attributes of the documents 
you give it to index. If you know, you are only ever going to search a subset of those, configure Loupe accordingly:

```php
$configuration = \Loupe\Loupe\Configuration::create()
    ->withSearchableAttributes(['title', 'overview'])
;
```

Note: Attributes you neither want to search or filter for are best kept **outside** of Loupe. Don't bother it with 
data that doesn't need to be processed.

[Paper]: https://hpi.de/fileadmin/user_upload/fachgebiete/naumann/publications/PDFs/2012_fenz_efficient.pdf