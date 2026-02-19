<?php

declare(strict_types=1);

namespace FancySparql\Tests\FancySparql\Formats;

use AssertionError;
use FancySparql\Dataset\Quad;
use FancySparql\Formats\NFormatParser;
use FancySparql\Formats\RdfXmlParser;
use FancySparql\Tests\Support\IsomorphicAsDatasetsConstraint;
use FancySparql\Tests\Support\W3CTestLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\Attributes\RequiresSetting;
use PHPUnit\Framework\TestCase;
use XMLReader;

use function array_values;
use function fopen;
use function iterator_to_array;
use function str_ends_with;
use function substr;

use const DIRECTORY_SEPARATOR;

/** @phpstan-import-type TripleOrQuadArray from Quad */
final class RdfXMLW3CTest extends TestCase
{
    /** @return array<string, array{string, list<TripleOrQuadArray>|null, string|null}> */
    public static function w3cTestProvider(): array
    {
        $rdfCases = W3CTestLoader::load(['*' . DIRECTORY_SEPARATOR . '*.rdf'], 'rdfxml');
        $ntCases  = W3CTestLoader::load(['*' . DIRECTORY_SEPARATOR . '*.nt'], 'rdfxml');

        $cases = [];
        foreach ($rdfCases as $name => [$path, $content, $documentBase]) {
            $ntName          = str_ends_with($name, '.rdf')
                ? substr($name, 0, -4) . '.nt'
                : $name . '.nt';
            $expectedTriples = null;

            if (isset($ntCases[$ntName])) {
                $ntContent       = $ntCases[$ntName][1];
                $expectedTriples = iterator_to_array(NFormatParser::parse($ntContent));
                $expectedTriples = array_values($expectedTriples);
            }

            $cases[$name] = [$path, $expectedTriples, $documentBase];
        }

        return $cases;
    }

    /** @return array<string, array{string, list<TripleOrQuadArray>}> */
    public static function goodXmlProvider(): array
    {
        $allCases  = self::w3cTestProvider();
        $goodCases = [];

        foreach ($allCases as $name => [$path, $expectedTriples, $documentBase]) {
            if ($expectedTriples === null) {
                continue;
            }

            $goodCases[$name] = [$path, $expectedTriples, $documentBase];
        }

        return $goodCases;
    }

    /** @param list<TripleOrQuadArray> $expected */
    #[DataProvider('goodXmlProvider')]
    public function testGoodXml(string $path, array $expected, string|null $documentBase): void
    {
        $stream = fopen($path, 'r');
        if ($stream === false) {
            self::fail('Failed to open file: ' . $path);
        }

        $reader = XMLReader::fromStream($stream, null, 0, $documentBase);
        $parser = new RdfXmlParser($reader);
        $got    = iterator_to_array($parser);

        self::assertThat($got, new IsomorphicAsDatasetsConstraint($expected, false));
    }

    /** @return array<string, array{string, string|null}> */
    public static function badXmlProvider(): array
    {
        $allCases = self::w3cTestProvider();
        $badCases = [];

        foreach ($allCases as $name => [$path, $expectedTriples, $documentBase]) {
            if ($expectedTriples !== null) {
                continue;
            }

            $badCases[$name] = [$path, $documentBase];
        }

        return $badCases;
    }

    /** When assertions are enabled, the parser should fail with the bad cases. */
    #[DataProvider('badXmlProvider')]
    #[RequiresSetting('zend.assertions', '1')]
    public function testBadXmlWithAssertions(string $path, string|null $documentBase): void
    {
        $stream = fopen($path, 'r');
        if ($stream === false) {
            self::fail('Failed to open file: ' . $path);
        }

        $reader = XMLReader::fromStream($stream, null, 0, $documentBase);
        $parser = new RdfXmlParser($reader);

        $this->expectException(AssertionError::class);
        iterator_to_array($parser);
    }

    /** When assertions are disabled, the parser should not crash. */
    #[DataProvider('badXmlProvider')]
    #[RequiresSetting('zend.assertions', '0')]
    #[DoesNotPerformAssertions]
    public function testBadXmlWithoutAssertions(string $path, string|null $documentBase): void
    {
        $stream = fopen($path, 'r');
        if ($stream === false) {
            self::fail('Failed to open file: ' . $path);
        }

        $reader = XMLReader::fromStream($stream, null, 0, $documentBase);
        $parser = new RdfXmlParser($reader);

        iterator_to_array($parser);
    }
}
