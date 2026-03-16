<?php

declare(strict_types=1);

namespace FancyRDF\Tests\FancyRDF\Formats;

use FancyRDF\Dataset\Quad;
use FancyRDF\Formats\NFormatParser;
use FancyRDF\Formats\RdfXmlParser;
use FancyRDF\Tests\Support\IsomorphicAsDatasetsConstraint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use XMLReader;

use function file_get_contents;
use function is_dir;
use function is_file;
use function iterator_to_array;
use function scandir;

use const DIRECTORY_SEPARATOR;

/** @phpstan-import-type TripleOrQuadArray from Quad */
final class RdfXmlParserTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function rdfParsesToTriplesProvider(): array
    {
        $cases = [];

        foreach (self::rdfxmlTestCases() as $name => $paths) {
            $cases['parser/' . $name] = [$paths['rdf'], $paths['nt']];
        }

        return $cases;
    }

    /** @return array<string, array{string, string}> */
    public static function serializedParsesToTriplesProvider(): array
    {
        $cases = [];

        foreach (self::rdfxmlTestCases() as $name => $paths) {
            $serialized = $paths['serialized'] ?? null;
            if ($serialized === null) {
                continue;
            }

            $cases['parser/' . $name . '-serialized'] = [$serialized, $paths['nt']];
        }

        return $cases;
    }

    /** @return array<string, array{rdf: string, nt: string, serialized?: string}> */
    public static function rdfxmlTestCases(): array
    {
        $baseDir = __DIR__ . DIRECTORY_SEPARATOR . 'testdata' . DIRECTORY_SEPARATOR . 'rdf';

        /** @var array<string, array{rdf: string, nt: string, serialized?: string}> $cases */
        $cases = [];

        $entries = scandir($baseDir);
        if ($entries === false) {
            return $cases;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $caseDir = $baseDir . DIRECTORY_SEPARATOR . $entry;
            if (! is_dir($caseDir)) {
                continue;
            }

            $rdfFile = $caseDir . DIRECTORY_SEPARATOR . $entry . '.rdf';
            $ntFile  = $caseDir . DIRECTORY_SEPARATOR . $entry . '.nt';

            if (! is_file($rdfFile) || ! is_file($ntFile)) {
                continue;
            }

            $serializedFile = $caseDir . DIRECTORY_SEPARATOR . $entry . '-serialized.rdf';

            $cases[$entry] = [
                'rdf' => $rdfFile,
                'nt' => $ntFile,
            ];

            if (! is_file($serializedFile)) {
                continue;
            }

            $cases[$entry]['serialized'] = $serializedFile;
        }

        return $cases;
    }

    #[DataProvider('rdfParsesToTriplesProvider')]
    #[DataProvider('serializedParsesToTriplesProvider')]
    #[TestDox('parser/{_dataName}')]
    public function testParsesRdfAsTriples(string $rdfFile, string $ntFile): void
    {
        $rdfSource = file_get_contents($rdfFile);
        self::assertNotFalse($rdfSource, 'Failed to read RDF/XML file: ' . $rdfFile);

        $reader = XMLReader::fromString($rdfSource);
        $parser = new RdfXmlParser($reader);

        $triples = iterator_to_array($parser);

        $ntSource = file_get_contents($ntFile);
        self::assertNotFalse($ntSource, 'Failed to read N-Triples file: ' . $ntFile);

        $expectedTriples = iterator_to_array(NFormatParser::parse($ntSource));
        self::assertThat($triples, new IsomorphicAsDatasetsConstraint($expectedTriples));
    }
}
