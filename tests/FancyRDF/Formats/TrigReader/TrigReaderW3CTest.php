<?php

declare(strict_types=1);

namespace FancyRDF\Tests\FancyRDF\Formats\TrigReader;

use FancyRDF\Dataset\Quad;
use FancyRDF\Formats\NFormatParser;
use FancyRDF\Formats\TrigReader\TrigReader;
use FancyRDF\Streaming\ResourceStreamReader;
use FancyRDF\Tests\Support\W3CTestLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\Attributes\RequiresSetting;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

use function array_values;
use function fclose;
use function feof;
use function fopen;
use function iterator_to_array;
use function str_ends_with;
use function substr;

/** @phpstan-import-type TripleOrQuadArray from Quad */
final class TrigReaderW3CTest extends TestCase
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

    /** @return array<string, array{string, string|null}> */
    public static function goodProvider(): array
    {
        $tests     = self::w3cTestProvider();
        $goodTests = [];
        foreach ($tests as $name => [$path, $expectedQuads, $documentBase]) {
            if ($expectedQuads === null) {
                continue;
            }

            $goodTests[$name] = [$path, $documentBase];
        }

        return $goodTests;
    }

    /** @return array<string, array{string, string|null}> */
    public static function badProvider(): array
    {
        $tests    = self::w3cTestProvider();
        $badTests = [];
        foreach ($tests as $name => [$path, $expectedQuads, $documentBase]) {
            if ($expectedQuads !== null) {
                continue;
            }

            $badTests[$name] = [$path, $documentBase];
        }

        return $badTests;
    }

    #[DataProvider('goodProvider')]
    #[TestDox('Good Trig file $path can tokenize the file')]
    public function testGoodTrig(string $path, string|null $documentBase): void
    {
        // open the file
        $stream = fopen($path, 'r');
        if ($stream === false) {
            self::fail('Failed to open file: ' . $path);
        }

        try {
            // create a reader
            $reader = new TrigReader(new ResourceStreamReader($stream));

            /** phpcs:disable Generic.CodeAnalysis.EmptyStatement.DetectedWhile */
            while ($reader->next()) {
                // do nothing
            }

            self::assertTrue(feof($stream), 'stream should be exhausted');
        } finally {
            fclose($stream);
        }
    }

    #[DataProvider('badProvider')]
    #[DoesNotPerformAssertions]
    #[RequiresSetting('zend.assertions', '0')]
    #[TestDox('Bad Trig file $path does not throw when assertions are disabled')]
    public function testBadTrigWithoutAssertions(string $path, string|null $documentBase): void
    {
        $stream = fopen($path, 'r');
        if ($stream === false) {
            self::fail('Failed to open file: ' . $path);
        }

        try {
            $reader = new TrigReader(new ResourceStreamReader($stream));

            /** phpcs:disable Generic.CodeAnalysis.EmptyStatement.DetectedWhile */
            while ($reader->next()) {
            }
        } finally {
            fclose($stream);
        }
    }
}
