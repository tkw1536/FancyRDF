<?php

declare(strict_types=1);

namespace FancyRDF\Tests\FancyRDF\Formats;

use FancyRDF\Dataset\Quad;
use FancyRDF\Formats\TrigParser;
use FancyRDF\Formats\TrigReader\TrigReader;
use FancyRDF\Term\BlankNode;
use FancyRDF\Term\Iri;
use FancyRDF\Term\Literal;
use FancyRDF\Tests\Support\IsomorphicAsDatasetsConstraint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function fopen;
use function fwrite;
use function iterator_to_array;
use function rewind;

/** @phpstan-import-type TripleOrQuadArray from Quad */
final class TrigParserTest extends TestCase
{
    private const string RDF_NS = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';

    /** @return resource */
    private static function openString(string $input): mixed
    {
        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            throw new RuntimeException('Failed to open memory stream');
        }

        if (fwrite($stream, $input) === false) {
            throw new RuntimeException('Failed to write to memory stream');
        }

        if (rewind($stream) === false) {
            throw new RuntimeException('Failed to rewind memory stream');
        }

        return $stream;
    }

    /** @return array<string, array{string, list<TripleOrQuadArray>, bool}> */
    public static function parseProvider(): array
    {
        return [
            'simple triple with literal' => [
                '<http://example.org/s> <http://example.org/p> "Hello" .',
                [
                    [
                        new Iri('http://example.org/s'),
                        new Iri('http://example.org/p'),
                        new Literal('Hello'),
                        null,
                    ],
                ],
                false,
            ],
            'with @prefix' => [
                <<<'TURTLE'
@prefix ex: <http://example.org/terms#> .
<http://example.org/subject> ex:title "Hello World" .
TURTLE,
                [
                    [
                        new Iri('http://example.org/subject'),
                        new Iri('http://example.org/terms#title'),
                        new Literal('Hello World'),
                        null,
                    ],
                ],
                false,
            ],
            'typed node with a' => [
                <<<'TURTLE'
@prefix ex: <http://example.org/terms#> .
<http://example.org/book1> a ex:Book ;
    ex:title "Dogs in Hats" .
TURTLE,
                [
                    [
                        new Iri('http://example.org/book1'),
                        new Iri(self::RDF_NS . 'type'),
                        new Iri('http://example.org/terms#Book'),
                        null,
                    ],
                    [
                        new Iri('http://example.org/book1'),
                        new Iri('http://example.org/terms#title'),
                        new Literal('Dogs in Hats'),
                        null,
                    ],
                ],
                false,
            ],
            'resource as object' => [
                <<<'TURTLE'
@prefix ex: <http://example.org/terms#> .
<http://example.org/person> ex:knows <http://example.org/friend> .
TURTLE,
                [
                    [
                        new Iri('http://example.org/person'),
                        new Iri('http://example.org/terms#knows'),
                        new Iri('http://example.org/friend'),
                        null,
                    ],
                ],
                false,
            ],
            'multiple triples' => [
                <<<'TURTLE'
@prefix ex: <http://example.org/terms#> .
<http://example.org/s1> ex:prop1 "value1" .
<http://example.org/s2> ex:prop2 "value2" .
TURTLE,
                [
                    [
                        new Iri('http://example.org/s1'),
                        new Iri('http://example.org/terms#prop1'),
                        new Literal('value1'),
                        null,
                    ],
                    [
                        new Iri('http://example.org/s2'),
                        new Iri('http://example.org/terms#prop2'),
                        new Literal('value2'),
                        null,
                    ],
                ],
                false,
            ],
            'blank node with label' => [
                <<<'TURTLE'
@prefix ex: <http://example.org/terms#> .
_:alice a ex:Person ;
    ex:name "Alice" .
TURTLE,
                [
                    [
                        new BlankNode('alice'),
                        new Iri(self::RDF_NS . 'type'),
                        new Iri('http://example.org/terms#Person'),
                        null,
                    ],
                    [
                        new BlankNode('alice'),
                        new Iri('http://example.org/terms#name'),
                        new Literal('Alice'),
                        null,
                    ],
                ],
                false,
            ],
            'object list' => [
                <<<'TURTLE'
@prefix ex: <http://example.org/terms#> .
<http://example.org/s> ex:name "Spiderman", "Человек-паук"@ru .
TURTLE,
                [
                    [
                        new Iri('http://example.org/s'),
                        new Iri('http://example.org/terms#name'),
                        new Literal('Spiderman'),
                        null,
                    ],
                    [
                        new Iri('http://example.org/s'),
                        new Iri('http://example.org/terms#name'),
                        new Literal('Человек-паук', 'ru', null),
                        null,
                    ],
                ],
                false,
            ],
            'TriG named graph' => [
                <<<'TRIG'
@prefix ex: <http://example.org/terms#> .
<http://example.org/g1> {
    <http://example.org/s> ex:p "in graph" .
}
TRIG,
                [
                    [
                        new Iri('http://example.org/s'),
                        new Iri('http://example.org/terms#p'),
                        new Literal('in graph'),
                        new Iri('http://example.org/g1'),
                    ],
                ],
                true,
            ],
            'TriG default graph block' => [
                <<<'TRIG'
@prefix ex: <http://example.org/terms#> .
{
    <http://example.org/s> ex:p "default" .
}
TRIG,
                [
                    [
                        new Iri('http://example.org/s'),
                        new Iri('http://example.org/terms#p'),
                        new Literal('default'),
                        null,
                    ],
                ],
                true,
            ],
        ];
    }

    /** @param list<TripleOrQuadArray> $expected */
    #[DataProvider('parseProvider')]
    #[TestDox('parses Turtle/TriG to expected triples or quads')]
    public function testParse(string $input, array $expected, bool $isTrig): void
    {
        $stream = self::openString($input);
        $reader = new TrigReader($stream);
        $parser = new TrigParser($reader, $isTrig);

        $parsed = iterator_to_array($parser);
        self::assertThat($parsed, new IsomorphicAsDatasetsConstraint($expected));
    }
}
