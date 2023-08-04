# Loupe - a search engine with only PHP and SQLite

They say that when you want to explain something to people, you should tell them a story. After all, we all read and 
hear stories every day, and it makes topics understandable and relatable. So here we go:

At the beginning of 2023, I was tasked with a project involving the collection and management of property data in 
the tourism industry. Hotels, restaurants and other POIs that could potentially be of interest to tourists. The 
expected number of objects was manageable, 2-3000 maybe. And also otherwise the requirements were rather
simple. A classic list with a detail view of the object for further information. In our industry actually 
basic input/output. But: the list should of course also be searchable, filterable and sortable.

That is the beginning of this story. And then I sat there, as you have probably sat there at least once in your life,
and said to myself: "All right, as always: Actually content management and super simple, only for the search the 
customer has to decide whether a simple `LIKE` query on an SQL database is enough, or whether it should be
[ElasticSearch][ElasticSearch], [MeiliSearch][MeiliSearch], [Algolia][Algolia], [Typesense][Typesense] and Co. Maybe 
the full-text search of a database like MySQL or PostgreSQL will do. Same procedure as every year, James".

Later in the discussion with the customer it came up that sorting the results by distance in kilometers from the 
current location would also be nice. Of course. This would've been still doable with a regular SQL database but we 
started to get into a direction where we definitely needed a search engine for this project.

And if I'm honest, this story has been repeated an estimated 5274392 times in my professional life. No matter how 
many objects and which data it was about. Each time, the choice of search engine has been a point of discussion. 
Connected to that, of course, whether to choose a SAAS product or to host it yourself, and if so, which software to 
choose. Always the same discussion, regardless of whether 500 objects or 10,000, the points to be clarified were 
always the same. The only difference to 2023: This year I was fed up. I didn't want to have this discussion every 
time - I didn't want to discuss the choice of search engine again because of a mere 3000 objects and a geo-distance 
sort. I wanted a solution for this project and the years to come. Because one thing was clear to me: this discussion 
will come again with the next project. That I am absolutely sure of.

So I started to work a bit more extensively with search engines and out of that, after many, many hours of hard work, came
my new baby, which I now opensource: [Loupe][Loupe].

"Loupe" is French and means magnifying glass and all it needs is PHP and an SQLite database. So you can even use 
Loupe in-memory. This may be just the right thing for you, depending on the purpose.

The question you may be asking yourself now is "Why?". Why yet another search engine? Why all this effort?

## How it came about

I simply wanted a solution for simple use cases. Loupe of course does not compete with any of the mentioned big 
players. I only wanted to build a replacement for an SQL `LIKE` query. So what I initially started with, was tokenizing 
a text and making this searchable. Then, things started to get a bit out of hand because I had been bitten by the bug!

First, I noticed that when searching for `cars` I would get no matches for `car`. So I've combined the libraries 
`patrickschur/language-detection` as well as `wamania/php-stemmer` and added automated language detection and 
[stemming][Stemming] support for better hits.

Then, I needed to be able to filter on any attribute, no matter whether it was a single value attribute like `age > 
42` or an array value for operations such as `department IN ('Project Management', 'Backoffice')` and the likes. So 
I read how to write a parser, took `doctrine/lexer` as an excellent foundation and wrote my own simple [AST][AST] 
and after a few hours, I could basically filter and sort on any attribute in my index.

Because my customer required filtering and sorting by geo distance, this was up next. Sorting on geo distance is 
pretty straightforward as well. Here, I could make use of the excellent `mjaschen/phpgeo` library to calculate the 
distance between two coordinates. Filtering happens just the same although here, I tried to be a bit smart. If you 
have thousands of documents in the search index and you want to only list those that are e.g. within `2km` of a 
given coordinate, you would like to exclude as many documents as possible before you actually calculate 
the exact distance for every single one. You will need to calculate the exact distance eventually anyway because those 
`2km` represent the radius of a circle. However, what you can do, is calculate a bounding box first. So essentially a 
square around your coordinate. You can then filter for all the documents within that square using simple `>=` and `<=` 
queries on indexed columns. Calculating the exact distance only needs to be done for the ones matching this 
requirement which results in better performance.

Up next was term highlighting. And after that I've finally managed to get around proper relevance sorting based on a 
very typical combination of [normalized tf-idf][tf-idf] and [cosine similarity][Cosine_Similarity].

And then there was my final enemy: Typo tolerance. I had played around with simple [Levenshtein distance][Levenshtein] calculations but searching while allowing typos quickly got very slow because you need to effectively 
calculate the distance for every single term and the bigger the search index, the more needed to be calculated 
making it effectively useless. So I searched the Internet and eventually stumbled over an [academic paper of 2012 
called "Efficient Similarity Search in Very Large String Sets"][State_Set_Index_Pub] by Dandy Fenz, Dustin Lange, Astrid Rheinländer, Felix Naumann,
and Ulf Leser from the Hasso Plattner Institute, Potsdam, Germany and Humboldt-Universität zu Berlin, Department of
Computer Science, Berlin, Germany.

I can't really explain the algorithm any better than what is done in this paper, so I suggest you read it, if you are 
interested in details. But essentially, every letter in all the terms gets assigned a number and because we now have 
numbers instead of letters, we are able to calculate possible outcomes with an allowed distance of e.g. `2` and 
search the database much more efficiently. I've implemented the algorithm in a [standalone library 
`toflar/state-set-index`][State_Set_Index] which you may checkout and use in your own projects as well.

Performant typo tolerance - yay! 🎉

## Summary

So, yes! Over time, it may have gotten a bit out of hand and I added more features than originally planned. But 
at the end of the day, still, Loupe only requires PHP and SQLite! You don't have to worry about anything, all the 
work has been done for you.

I also don't plan to add support for other databases, because SQLite is everywhere and offers the possibility to 
register your own SQL functions, which Loupe benefits from.

Thanks to SQLite, Loupe could also become interesting for embedded systems such as apps built with [NativePHP][NativePHP]
or similar.

So let's recap! Loupe…

* …only requires PHP and SQLite, you don't need anything else - no containers, no nothing
* …is typo-tolerant (based on the State Set Index Algorithm and Levenshtein)
* …supports phrase search using `"` quotation marks
* …supports filtering (and ordering) on any attribute with any SQL-inspired filter statement
* …supports filtering (and ordering) on Geo distance
* …orders relevance based on a typical TF-IDF Cosine similarity algorithm
* …auto-detects languages
* …supports stemming
* …is very easy to use
* …is all-in-all just the easiest way to replace your good old SQL `LIKE %...%` queries with a way better search
  experience but without all the hassle of an additional service to manage. SQLite is everywhere and all it needs is
  your filesystem.

## Full Disclosure

If you are familiar with MeiliSearch, you will notice that the API is very much inspired by 
MeiliSearch. The reasons for this are simple:

1. First and foremost: I think, they did an amazing job of keeping configuration simple and understandable from a
   developer's perspective. Basic search tools shouldn't be complicated.
2. If Loupe shouldn't be enough for your use case anymore (you need advanced features, better performance etc.),
   switching to MeiliSearch instead should be a piece of cake.

## Final words

I've created Loupe 100% by myself in my spare time. If it helps you - great! If it doesn't: There are a great 
deal of excellent search engines on the market. I have no clue how performant Loupe is with e.g. 50,000 documents. 
Maybe it is, maybe it's not. Remember: Use a hammer to bang in a nail. Use a screwdriver to tighten screws. Use the 
right tool for the job - maybe Loupe is not.

If you want to sponsor my Open Source work you may do so via [GitHub Sponsors][GitHub_Sponsor] and you can always 
find me on [Mastodon][Mastodon] and [Twitter][Twitter] (or whatever this thing is called now).

Thanks for reading!

Yanick

[Loupe]: https://github.com/loupe-php/loupe
[NativePHP]: https://nativephp.com
[MeiliSearch]: https://www.meilisearch.com
[ElasticSearch]: https://www.elastic.co
[Algolia]: https://www.algolia.com
[Typesense]: https://typesense.org
[Stemming]: https://en.wikipedia.org/wiki/Stemming
[AST]: https://en.wikipedia.org/wiki/Abstract_syntax_tree
[tf-idf]: https://en.wikipedia.org/wiki/Tf%E2%80%93idf
[Cosine_Similarity]: https://en.wikipedia.org/wiki/Cosine_similarity
[Levenshtein]: https://en.wikipedia.org/wiki/Levenshtein_distance
[State_Set_Index_Pub]: https://hpi.de/fileadmin/user_upload/fachgebiete/naumann/publications/PDFs/2012_fenz_efficient.pdf
[State_Set_Index]: https://github.com/Toflar/state-set-index
[GitHub_Sponsor]: https://github.com/sponsors/Toflar
[Mastodon]: https://phpc.social/@toflar
[Twitter]: https://twitter.com/toflar