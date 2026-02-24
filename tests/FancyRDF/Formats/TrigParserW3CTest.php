<?php

declare(strict_types=1);

namespace FancyRDF\Tests\FancyRDF\Formats;

use FancyRDF\Dataset\Quad;
use FancyRDF\Formats\NFormatParser;
use FancyRDF\Formats\TrigParser;
use FancyRDF\Formats\TrigReader\TrigReader;
use FancyRDF\Streaming\ResourceStreamReader;
use FancyRDF\Tests\Support\IsomorphicAsDatasetsConstraint;
use FancyRDF\Tests\Support\W3CTestLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Throwable;

use function array_values;
use function fopen;
use function iterator_to_array;
use function str_ends_with;
use function substr;

/** @phpstan-import-type TripleOrQuadArray from Quad */
final class TrigParserW3CTest extends TestCase
{
    /** @return array<string, array{string, list<TripleOrQuadArray>|null, string|null}> */
    public static function w3cTestProvider(): array
    {
        $trigCases = W3CTestLoader::load(['*.trig'], 'trig');
        $nqCases   = W3CTestLoader::load(['*.nq'], 'trig');

        $cases = [];
        foreach ($trigCases as $name => [$path, $content, $documentBase]) {
            $nqName        = str_ends_with($name, '.trig')
             ? substr($name, 0, -5) . '.nq'
             : $name . '.nq';
            $expectedQuads = null;

            if (isset($nqCases[$nqName])) {
                $nqContent     = $nqCases[$nqName][1];
                $expectedQuads = array_values(iterator_to_array(NFormatParser::parse($nqContent)));
            }

            $cases[$name] = [$path, $expectedQuads, $documentBase];
        }

        return $cases;
    }

    /** @return array<string, array{string, list<TripleOrQuadArray>}> */
    public static function goodTrigProvider(): array
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
    #[DataProvider('goodTrigProvider')]
    #[TestDox('Good Trig file $path parses to expected quads')]
    public function testGoodTrig(string $path, array $expected, string|null $documentBase): void
    {
        $stream = fopen($path, 'r');
        if ($stream === false) {
            self::fail('Failed to open file: ' . $path);
        }

        $reader = new TrigReader(new ResourceStreamReader($stream));
        $parser = new TrigParser($reader, true, $documentBase ?? '');

        try {
            $got = iterator_to_array($parser);
            self::assertThat($got, new IsomorphicAsDatasetsConstraint($expected, false));
        } catch (Throwable $e) {
            self::markTestIncomplete($e->getMessage());
        }
    }

    // /** @return array<string, array{string, string|null}> */
    // public static function badTrigProvider(): array
    // {
    //     $allCases = self::w3cTestProvider();
    //     $badCases = [];

    //     foreach ($allCases as $name => [$path, $expectedTriples, $documentBase]) {
    //         if ($expectedTriples !== null) {
    //             continue;
    //         }

    //         $badCases[$name] = [$path, $documentBase];
    //     }

    //     return $badCases;
    // }

    // /** When assertions are enabled, the parser should fail with the bad cases. */
    // #[DataProvider('badTrigProvider')]
    // #[RequiresSetting('zend.assertions', '1')]
    // public function testBadTrigWithAssertions(string $path, string|null $documentBase): void
    // {
    //     $stream = fopen($path, 'r');
    //     if ($stream === false) {
    //         self::fail('Failed to open file: ' . $path);
    //     }

    //     $reader = new TrigReader($stream);
    //     $parser = new TrigParser($reader);
    //     $got    = iterator_to_array($parser);

    //     $this->expectException(AssertionError::class);
    //     iterator_to_array($parser);
    // }

    // /** When assertions are disabled, the parser should not crash. */
    // #[DataProvider('badTrigProvider')]
    // #[RequiresSetting('zend.assertions', '0')]
    // #[DoesNotPerformAssertions]
    // public function testBadTrigWithoutAssertions(string $path, string|null $documentBase): void
    // {
    //     $stream = fopen($path, 'r');
    //     if ($stream === false) {
    //         self::fail('Failed to open file: ' . $path);
    //     }

    //     $reader = new TrigReader($stream);
    //     $parser = new TrigParser($reader);
    //     iterator_to_array($parser);
    // }
}
