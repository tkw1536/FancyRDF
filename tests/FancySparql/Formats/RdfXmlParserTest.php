<?php

declare(strict_types=1);

namespace FancySparql\Tests\FancySparql\Formats;

use FancySparql\Dataset\Quad;
use FancySparql\Formats\RdfXmlParser;
use FancySparql\Term\Literal;
use FancySparql\Term\Resource;
use FancySparql\Tests\Support\IsomorphicAsDatasetsConstraint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XMLReader;

use function iterator_to_array;

/** @phpstan-import-type TripleOrQuadArray from Quad */
final class RdfXmlParserTest extends TestCase
{
    /** @return array<string, array{string, list<TripleOrQuadArray>}> */
    public static function parseProvider(): array
    {
        $rdfNs = RdfXmlParser::RDF_NAMESPACE;

        return [
            'simple description with literal' => [
                <<<XML
<?xml version="1.0"?>
<rdf:RDF xmlns:rdf="{$rdfNs}" xmlns:ex="http://example.org/terms#">
  <rdf:Description rdf:about="http://example.org/subject">
    <ex:title>Hello World</ex:title>
  </rdf:Description>
</rdf:RDF>
XML,
                [
                    [
                        new Resource('http://example.org/subject'),
                        new Resource('http://example.org/terms#title'),
                        new Literal('Hello World'),
                        null,
                    ],
                ],
            ],
            'typed node with rdf:about' => [
                <<<XML
<?xml version="1.0"?>
<rdf:RDF xmlns:rdf="{$rdfNs}" xmlns:ex="http://example.org/terms#">
  <ex:Book rdf:about="http://example.org/book1">
    <ex:title>Dogs in Hats</ex:title>
  </ex:Book>
</rdf:RDF>
XML,
                [
                    [
                        new Resource('http://example.org/book1'),
                        new Resource($rdfNs . 'type'),
                        new Resource('http://example.org/terms#Book'),
                        null,
                    ],
                    [
                        new Resource('http://example.org/book1'),
                        new Resource('http://example.org/terms#title'),
                        new Literal('Dogs in Hats'),
                        null,
                    ],
                ],
            ],
            'resource property' => [
                <<<XML
<?xml version="1.0"?>
<rdf:RDF xmlns:rdf="{$rdfNs}" xmlns:ex="http://example.org/terms#">
  <rdf:Description rdf:about="http://example.org/person">
    <ex:knows rdf:resource="http://example.org/friend"/>
  </rdf:Description>
</rdf:RDF>
XML,
                [
                    [
                        new Resource('http://example.org/person'),
                        new Resource('http://example.org/terms#knows'),
                        new Resource('http://example.org/friend'),
                        null,
                    ],
                ],
            ],
            'multiple descriptions' => [
                <<<XML
<?xml version="1.0"?>
<rdf:RDF xmlns:rdf="{$rdfNs}" xmlns:ex="http://example.org/terms#">
  <rdf:Description rdf:about="http://example.org/s1">
    <ex:prop1>value1</ex:prop1>
  </rdf:Description>
  <rdf:Description rdf:about="http://example.org/s2">
    <ex:prop2>value2</ex:prop2>
  </rdf:Description>
</rdf:RDF>
XML,
                [
                    [
                        new Resource('http://example.org/s1'),
                        new Resource('http://example.org/terms#prop1'),
                        new Literal('value1'),
                        null,
                    ],
                    [
                        new Resource('http://example.org/s2'),
                        new Resource('http://example.org/terms#prop2'),
                        new Literal('value2'),
                        null,
                    ],
                ],
            ],
            'blank node with rdf:nodeID' => [
                <<<XML
<?xml version="1.0"?>
<rdf:RDF xmlns:rdf="{$rdfNs}" xmlns:ex="http://example.org/terms#">
  <ex:Person rdf:nodeID="n1">
    <ex:name>Alice</ex:name>
  </ex:Person>
</rdf:RDF>
XML,
                [
                    [
                        new Resource('_:n1'),
                        new Resource($rdfNs . 'type'),
                        new Resource('http://example.org/terms#Person'),
                        null,
                    ],
                    [
                        new Resource('_:n1'),
                        new Resource('http://example.org/terms#name'),
                        new Literal('Alice'),
                        null,
                    ],
                ],
            ],
        ];
    }

    /** @param list<TripleOrQuadArray> $expectedQuads */
    #[DataProvider('parseProvider')]
    public function testParse(string $xml, array $expectedQuads): void
    {
        $reader = XMLReader::fromString($xml);
        $parser = new RdfXmlParser($reader);

        $parsed = iterator_to_array($parser);
        self::assertThat($parsed, new IsomorphicAsDatasetsConstraint($expectedQuads));
    }
}
