# FancyRDF

A streaming PHP 8.3+ PHP Library for [RDF 1.1](https://www.w3.org/TR/rdf11-concepts/) and eventually SPARQL focusing on standards compliance and proper typing.
This library is eventually intended to replace [EasyRDF](https://www.easyrdf.org) in [WissKI](https://wiss-ki.eu). 
It is currently a work-in-progress.

## Coding Standard & Typing

The code should be formatted using the [Doctrine Coding Standard](https://www.doctrine-project.org/projects/doctrine-coding-standard/en/14.0/reference/index.html#introduction) with the exception of line length.
This is enforced using [phpcs](https://github.com/squizlabs/PHP_CodeSniffer).

The code should also pass [phpstan](https://phpstan.org) on strictest settings.


The Makefile target `make lint` runs both phpcs and phpstan.
The Makefile target `make fmt` runs the autoformatter.

## Testing

The library contains a complete test suite using [phpunit](https://phpunit.de/).

The Makefile target `make test` runs the tests. 

## License

There is no license for the library and primary testing code, as the code is still in development.
Some of the test data in the `rdf_tests` has been adapted from W3C, licensing information can be found in the README in that directory.