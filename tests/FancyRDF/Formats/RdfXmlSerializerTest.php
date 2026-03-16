<?php

declare(strict_types=1);

namespace FancyRDF\Tests\FancyRDF\Formats;

use DOMDocument;
use FancyRDF\Dataset\Quad;
use FancyRDF\Formats\RdfXmlParser;
use FancyRDF\Formats\RdfXmlSerializer;
use FancyRDF\Tests\Support\LocalRdfTestCases;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use XMLReader;
use XMLWriter;

use function file_get_contents;
use function iterator_to_array;

use const DIRECTORY_SEPARATOR;

/**
 * @phpstan-import-type TripleArray from Quad
 * @phpstan-import-type TripleOrQuadArray from Quad
 */
final class RdfXmlSerializerTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function serializeProvider(): array
    {
        $baseDir = __DIR__ . DIRECTORY_SEPARATOR . 'testdata' . DIRECTORY_SEPARATOR . 'rdf';
        $all     = LocalRdfTestCases::load($baseDir);

        $cases = [];

        foreach ($all as $name => $paths) {
            if ($paths['rdf'] === null || $paths['rdf_serialized'] === null) {
                continue;
            }

            $cases[$name] = [$paths['rdf'], $paths['rdf_serialized']];
        }

        return $cases;
    }

    /**
     * @param string $rdfFile        Path to the input RDF/XML file.
     * @param string $serializedFile Path to the expected serialized RDF/XML file.
     */
    #[DataProvider('serializeProvider')]
    #[TestDox('serialize {name}')]
    public function testSerialize(string $rdfFile, string $serializedFile): void
    {
        $rdfSource = file_get_contents($rdfFile);
        self::assertNotFalse($rdfSource, 'Failed to read input file: ' . $rdfFile);

        $parser  = new RdfXmlParser(XMLReader::fromString($rdfSource));
        $triples = iterator_to_array($parser);

        $actual = self::serializeTriples($triples);

        $expected = file_get_contents($serializedFile);
        self::assertNotFalse($expected, 'Failed to read expected file: ' . $serializedFile);

        self::assertSame(
            self::canonicalizeXml($expected),
            self::canonicalizeXml($actual),
        );
    }

    /**
     * Serializes a list of triples to RDF/XML.
     *
     * @param iterable<TripleOrQuadArray> $triples
     */
    private static function serializeTriples(mixed $triples): string
    {
        $writer = new XMLWriter();
        $writer->openMemory();

        $serializer = new RdfXmlSerializer($writer);
        foreach ($triples as $triple) {
            if (! Quad::isTriple($triple)) {
                throw new RuntimeException('Expected a triple, got a quad');
            }

            $serializer->write($triple);
        }

        $serializer->close();

        return $writer->outputMemory();
    }

    private static function canonicalizeXml(string $xml): string
    {
        $doc                     = new DOMDocument();
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput       = false;

        self::assertTrue($doc->loadXML($xml), 'failed to parse XML: ' . $xml);

        $canonical = $doc->C14N(true, false);
        self::assertNotFalse($canonical, 'Failed to canonicalize XML');

        return $canonical;
    }
}
