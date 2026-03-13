<?php

declare(strict_types=1);

namespace FancyRDF\Tests\FancyRDF\Formats;

use DOMDocument;
use FancyRDF\Dataset\Quad;
use FancyRDF\Formats\NFormatParser;
use FancyRDF\Formats\RdfXmlSerializer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use XMLWriter;

use function assert;
use function basename;
use function file_get_contents;
use function glob;
use function iterator_to_array;
use function substr;

use const DIRECTORY_SEPARATOR;

/** @phpstan-import-type TripleArray from Quad */
final class RdfXmlSerializerTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function serializeProvider(): array
    {
        $baseDir = __DIR__ . DIRECTORY_SEPARATOR . 'testdata' . DIRECTORY_SEPARATOR . 'rdfxml-serializer';

        $cases   = [];
        $ntFiles = glob($baseDir . DIRECTORY_SEPARATOR . '*.nt');
        if ($ntFiles === false) {
            throw new RuntimeException('Failed to glob nt files in ' . $baseDir);
        }

        foreach ($ntFiles as $ntFile) {
            $baseName = basename($ntFile);
            $name     = substr($baseName, 0, -3);
            $rdfFile  = $baseDir . DIRECTORY_SEPARATOR . $name . '.rdf';

            $cases[$name] = [$ntFile, $rdfFile];
        }

        return $cases;
    }

    /**
     * @param string $ntFile  Path to the input N-Triples file.
     * @param string $rdfFile Path to the expected RDF/XML file.
     */
    #[DataProvider('serializeProvider')]
    public function testSerialize(string $ntFile, string $rdfFile): void
    {
        $ntContents = file_get_contents($ntFile);
        self::assertNotFalse($ntContents, 'Failed to read input file: ' . $ntFile);

        $triples = iterator_to_array(NFormatParser::parse($ntContents));

        $writer = new XMLWriter();
        $writer->openMemory();

        $serializer = new RdfXmlSerializer(
            $writer,
            ['ex' => 'http://example.org/terms#'],
        );

        foreach ($triples as $triple) {
            assert(Quad::isTriple($triple));
            $serializer->write($triple);
        }

        $serializer->close();

        $actual = $writer->outputMemory();

        $expected = file_get_contents($rdfFile);
        self::assertNotFalse($expected, 'Failed to read expected file: ' . $rdfFile);

        self::assertSame(
            self::canonicalizeXml($expected),
            self::canonicalizeXml($actual),
        );
    }

    private static function canonicalizeXml(string $xml): string
    {
        $doc                     = new DOMDocument();
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput       = false;

        self::assertTrue($doc->loadXML($xml), 'Failed to parse XML');

        $canonical = $doc->C14N(true, false);
        self::assertNotFalse($canonical, 'Failed to canonicalize XML');

        return $canonical;
    }
}
