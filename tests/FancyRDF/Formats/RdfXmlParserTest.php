<?php

declare(strict_types=1);

namespace FancyRDF\Tests\FancyRDF\Formats;

use FancyRDF\Dataset\Quad;
use FancyRDF\Formats\NFormatParser;
use FancyRDF\Formats\RdfXmlParser;
use FancyRDF\Tests\Support\IsomorphicAsDatasetsConstraint;
use FancyRDF\Tests\Support\LocalRdfTestCases;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use XMLReader;

use function file_get_contents;
use function iterator_to_array;

use const DIRECTORY_SEPARATOR;

/** @phpstan-import-type TripleOrQuadArray from Quad */
final class RdfXmlParserTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function rdfParsesToTriplesProvider(): array
    {
        $cases = [];

        foreach (self::rdfxmlTestCases() as $name => $paths) {
            if ($paths['rdf'] === null) {
                continue;
            }

            $cases['parser/' . $name] = [$paths['rdf'], $paths['nt']];
        }

        return $cases;
    }

    /** @return array<string, array{string, string}> */
    public static function serializedParsesToTriplesProvider(): array
    {
        $cases = [];

        foreach (self::rdfxmlTestCases() as $name => $paths) {
            if ($paths['rdf_serialized'] === null) {
                continue;
            }

            $cases['parser/' . $name . '-serialized'] = [$paths['rdf_serialized'], $paths['nt']];
        }

        return $cases;
    }

    /**
     * @return array<string, array{
     *     nt: string,
     *     rdf: string | null,
     *     rdf_serialized: string | null,
     * }>
     */
    public static function rdfxmlTestCases(): array
    {
        $baseDir = __DIR__ . DIRECTORY_SEPARATOR . 'testdata' . DIRECTORY_SEPARATOR . 'rdf';

        $cases = [];
        foreach (LocalRdfTestCases::load($baseDir) as $name => $paths) {
            $rdf           = $paths['rdf'];
            $nt            = $paths['nt'];
            $rdfSerialized = $paths['rdf_serialized'];

            if ($nt === null) {
                continue;
            }

            $cases[$name] = [
                'nt' => $nt,
                'rdf' => $rdf,
                'rdf_serialized' => $rdfSerialized,
            ];
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

        $expectedTriples = iterator_to_array((new NFormatParser())->parse($ntSource));
        self::assertThat($triples, new IsomorphicAsDatasetsConstraint($expectedTriples));
    }
}
