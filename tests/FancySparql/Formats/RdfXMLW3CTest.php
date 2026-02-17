<?php

declare(strict_types=1);

namespace FancySparql\Tests\FancySparql\Formats;

use FancySparql\Dataset\Quad;
use FancySparql\Formats\NFormatParser;
use FancySparql\Formats\RdfXmlParser;
use FancySparql\Tests\Support\IsomorphicAsDatasetsConstraint;
use FancySparql\Tests\Support\W3CTestLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;
use XMLReader;

use function array_values;
use function fopen;
use function iterator_to_array;
use function str_ends_with;
use function strlen;
use function strpos;
use function substr;

use const DIRECTORY_SEPARATOR;

/** @phpstan-import-type TripleOrQuadArray from Quad */
final class RdfXMLW3CTest extends TestCase
{
    /** @return array<string, array{string, list<TripleOrQuadArray>|null}> */
    public static function w3cTestProvider(): array
    {
        $rdfCases = W3CTestLoader::load(['*' . DIRECTORY_SEPARATOR . '*.rdf'], 'rdfxml');
        $ntCases  = W3CTestLoader::load(['*' . DIRECTORY_SEPARATOR . '*.nt'], 'rdfxml');

        $cases = [];
        foreach ($rdfCases as $name => [$path, $content]) {
            $ntName          = str_ends_with($name, '.rdf')
                ? substr($name, 0, -4) . '.nt'
                : $name . '.nt';
            $expectedTriples = null;

            if (isset($ntCases[$ntName])) {
                $ntContent       = $ntCases[$ntName][1];
                $expectedTriples = iterator_to_array(NFormatParser::parse($ntContent));
                $expectedTriples = array_values($expectedTriples);
            }

            $cases[$name] = [$path, $expectedTriples];
        }

        return $cases;
    }

    /** @return array<string, array{string, list<TripleOrQuadArray>}> */
    public static function goodTriplesProvider(): array
    {
        $allCases  = self::w3cTestProvider();
        $goodCases = [];

        foreach ($allCases as $name => [$path, $expectedTriples]) {
            if ($expectedTriples === null) {
                continue;
            }

            $goodCases[$name] = [$path, $expectedTriples];
        }

        return $goodCases;
    }

    /** @param list<TripleOrQuadArray> $expectedTriples */
    #[DataProvider('goodTriplesProvider')]
    public function testGoodTriplesAreIsomorphic(string $path, array $expectedTriples): void
    {
        try {
            // Get the document base URL.
            $rdfTestsPos = strpos($path, 'rdf_tests' . DIRECTORY_SEPARATOR . 'rdfxml' . DIRECTORY_SEPARATOR);
            if ($rdfTestsPos !== false) {
                $relativePath = substr($path, $rdfTestsPos + strlen('rdf_tests' . DIRECTORY_SEPARATOR . 'rdfxml' . DIRECTORY_SEPARATOR));
                $documentBase = 'https://w3c.github.io/rdf-tests/rdf/rdf11/rdf-xml/' . $relativePath;
            } else {
                $documentBase = null;
            }

            $stream = fopen($path, 'r');
            if ($stream === false) {
                self::fail('Failed to open file: ' . $path);
            }

            $reader = XMLReader::fromStream($stream, null, 0, $documentBase);
            $parser = new RdfXmlParser($reader);

            $parsed = iterator_to_array($parser);

            self::assertThat($parsed, new IsomorphicAsDatasetsConstraint($expectedTriples));
        } catch (Throwable $e) {
            self::markTestIncomplete($e->getMessage());
        }
    }
}
