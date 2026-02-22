# FancyRDF

> [!WARNING]
> This library is still a work in progress.
> It is neither feature complete, nor ready for production use.

[![Tests](https://github.com/tkw1536/FancyRDF/actions/workflows/test.yml/badge.svg)](https://github.com/tkw1536/FancyRDF/actions/workflows/test.yml)

A streaming PHP 8.4+ Library for [RDF 1.1](https://www.w3.org/TR/rdf11-concepts/) and eventually SPARQL focusing on standards compliance and proper typing.
When run with [PHP Assertions](https://www.php.net/manual/en/function.assert.php) enabled, any non-compliant document may produce an assertion.
When run in production mode, all test cases do not throw, but may provide erroneous output.

The library is currently a work-in-progress.
This library is eventually intended to replace [EasyRDF](https://www.easyrdf.org) in [WissKI](https://wiss-ki.eu). 

## Features

This library provides data structures for the following:

- ✅ [RDF 1.1 Term](https://www.w3.org/TR/rdf11-concepts/#dfn-rdf-triple)s in [Term](src/Term/Term.php) class
    - support serialization to / parsing from `JSON` as part of [SPARQL 1.1 Results JSON](https://www.w3.org/TR/2013/REC-sparql11-results-json-20130321/)
    - support serialization to / parsing from `XML` as part of [SPARQL 1.1 Results XML](https://www.w3.org/TR/2013/REC-rdf-sparql-XMLres-20130321/)
    - full support for literal term equality
    - minimal support for per-datatype equality
- ✅ [RDF 1.1 Datasets](https://www.w3.org/TR/rdf11-concepts/#dfn-rdf-triple)s in [Dataset](src/Dataset/Dataset.php) class
    - consist only of a set of triples
    - can be checked for equivalence, taking into account blank nodes
- ✅ [RFC 3986 URI References](https://www.rfc-editor.org/rfc/rfc3986) and [RFC 3987 IRI References](https://www.rfc-editor.org/rfc/rfc3987) in [UriReference](src/Uri/UriReference.php) class
    - can parse from / serialize to a string
    - can resolve a reference against a base URI

The library provides several stream-based implementations of parsers and serializers for datasets:

> [!TIP]
> Here streaming means that instead of reading a dataset into memory in its entirety, reading it piece-by-piece as needed.
> This approach saves memory usage and improves efficiency, in particular when working with large datasets.

- [RDF/XML](https://www.w3.org/TR/rdf-xml/)
    - ✅ Parser: [RdfXmlParser](src/Formats/RdfXmlParser.php) 
    - [ ] Serializer: TODO
    - ✅ passes W3C [Test Suite](https://www.w3.org/2013/RDFXMLTests/)
        - ✅ all positive tests parse correctly and produce equivalent N-Triples datasets
        - ✅ all negative tests produce an assertion error in development mode.
        - ✅ all negative tests do not produce errors in production mode.
- [ ] [Turtle](https://www.w3.org/TR/turtle/)
    - [ ] Parser: TODO
    - [ ] Serializer: TODO
- [N-Triples](https://www.w3.org/TR/n-triples/) and [N-Quads](https://www.w3.org/TR/n-quads/)
    - ✅ Parser: [NFormatParser](src/Formats/NFormatParser.php)
    - ✅ Serializer: [NFormatSerializer](src/Formats/NFormatSerializer.php)
    - ✅ can pass W3C [Test Suite for N-Triples](https://www.w3.org/2013/N-TriplesTests/) and [Test Suite for N-Quads](https://www.w3.org/2013/N-QuadsTests/)
        - ✅ all positive tests parse and round-trip correctly.
        - ✅ all negative tests produce an assertion error in development mode.
        - ✅ all negative tests do not produce errors in production mode. 

## Dependencies

- [PHP 8.4+](https://www.php.net/releases/8.4/en.php) with extensions:
    - [ext-curl](https://www.php.net/manual/en/book.curl.php)
    - [ext-dom](https://www.php.net/manual/en/book.dom.php)
    - [ext-json](https://www.php.net/manual/en/book.json.php)
    - [ext-mbstring](https://www.php.net/manual/en/book.mbstring.php)
    - [ext-pcre](https://www.php.net/manual/en/book.pcre.php)
- [Guzzle](https://github.com/guzzle/guzzle) 7+

## Coding Standard & Typing

The code should be formatted using the [Doctrine Coding Standard](https://www.doctrine-project.org/projects/doctrine-coding-standard/en/14.0/reference/index.html#introduction) with the exception of line length.
This is enforced using [phpcs](https://github.com/squizlabs/PHP_CodeSniffer).

The code should also pass [phpstan](https://phpstan.org) on strictest settings.

The Makefile target `make lint` runs both phpcs and phpstan.
The Makefile target `make fmt` runs the autoformatter.

## Spell checking

Spell checking is done with [cspell](https://cspell.org/).
Configuration is in [cspell.json](cspell.json) (custom words and ignore patterns).

The Makefile target `make cspell` runs the spell checker.
This requires `cspell` to be installed and available on your `PATH`.

## Testing

The library contains a complete test suite using [phpunit](https://phpunit.de/).

The Makefile target `make test` runs the tests. 

## License

> [!WARNING]
> A license will be added once the library has reached feature completion.

There is no license for the library and primary testing code, as the code is still in development.

Some of the test data in `rdf_tests` has been adapted from W3C and other sources and is therefore licensed under specific conditions.
See licensing information in the [rdf_tests/README.md](rdf_tests/README.md) file for details.