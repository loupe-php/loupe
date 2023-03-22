# Loupe

An SQLite based, PHP-only fulltext search engine inspired by MeiliSearch.

Loupe…

* …is completely dependency-free (other than PHP and SQLite, you don't need anything - no containers, no nothing)
* …is typo-tolerant (based on Levenshtein)
* …is very easy to use
* …is easily fast enough for at least 50k documents (50k is defensive)
* …supports filtering (and ordering) on any attribute with any SQL-inspired filter statement
* …supports filtering (and ordering) on Geo distance
* …orders relevance based on a typical TF-IDF Cosine similarity algorithm
* …auto-detects languages
* …supports stemming
* …is all-in-all just the easiest way to replace your good old SQL `LIKE %...%` queries with a way better search 
  experience but without all the hassle of an additional service to manage. SQLite is everywhere and all it needs is 
  your file system.

## TODOs

* Order by relevance based on a typical TF-IDF Cosine similarity algorithm
* Filtering by Geo distance


## Acknowledgement

If you are familiar with MeiliSearch, you will notice that the API is extremely similar to it. The
reasons for this are simple:

1. First and foremost: I think, they did an amazing job of keeping configuration simple and understandable from a 
   developer's perspective. Basic search tools shouldn't be complicated.
2. If Loupe shouldn't be enough for your use case anymore (you need advanced features, better performance etc.), 
   switching to MeiliSearch instead should be as easy as possible.

I even took the liberty to copy some of their test data to feed Loupe for functional tests.