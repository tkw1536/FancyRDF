<?php

declare(strict_types=1);

namespace FancySparql\Tests\Formats;

use AssertionError;
use Exception;
use FancySparql\Dataset\Quad;
use FancySparql\Formats\NFormatParser;
use FancySparql\Formats\NFormatSerializer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RequiresSetting;
use PHPUnit\Framework\TestCase;

use function array_merge;
use function basename;
use function count;
use function dirname;
use function file_get_contents;
use function glob;
use function implode;
use function iterator_to_array;

use const DIRECTORY_SEPARATOR;

/** @phpstan-import-type TripleOrQuadArray from Quad */
final class NFormatW3CTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     *
     * @throws Exception
     */
    private static function getFilesInDir(string $dir, string $pattern): array
    {
        $cases     = [];
            $files = glob($dir . DIRECTORY_SEPARATOR . $pattern);
        if ($files === false) {
            throw new Exception('Failed to read files: ' . $dir . DIRECTORY_SEPARATOR . $pattern);
        }

        foreach ($files as $path) {
            $name    = basename($path);
            $content = file_get_contents($path);
            if ($content === false) {
                throw new Exception('Failed to read file: ' . $path);
            }

            $cases[$name] = [$path, $content];
        }

        return $cases;
    }

    /** @return array<string, array{string, string}> */
    public static function goodNonEmptyTriplesProvider(): array
    {
        return self::getFilesInDir(
            dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'rdf_tests' . DIRECTORY_SEPARATOR . 'ntriples' . DIRECTORY_SEPARATOR . 'good',
            '*.nt',
        );
    }

    #[DataProvider('goodNonEmptyTriplesProvider')]
    public function testGoodNonEmptyTriples(string $path, string $content): void
    {
        $statements = NFormatParser::parse($content);

        $count = 0;
        foreach ($statements as $statement) {
            $this->assertNull($statement[3], 'should only return a triple');
            $count++;
        }

        $this->assertGreaterThanOrEqual(1, $count, 'should return a non-zero number of statements');
    }

    /** @return array<string, array{string, string}> */
    public static function goodNonEmptyQuadsProvider(): array
    {
        return self::getFilesInDir(
            dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'rdf_tests' . DIRECTORY_SEPARATOR . 'nquads' . DIRECTORY_SEPARATOR . 'good',
            '*.nq',
        );
    }

    #[DataProvider('goodNonEmptyQuadsProvider')]
    public function testGoodNonEmptyQuads(string $path, string $content): void
    {
        $statements = NFormatParser::parse($content);

        $count = 0;
        foreach ($statements as $statement) {
            $this->assertNotNull($statement[3], 'must return a quadruple');
            $count++;
        }

        $this->assertGreaterThanOrEqual(1, $count, 'should return a non-zero number of statements');
    }

    /** @return array<string, array{string, string}> */
    public static function roundTripProvider(): array
    {
        return array_merge(
            self::goodNonEmptyTriplesProvider(),
            self::emptyTriplesProvider(),
            self::goodNonEmptyQuadsProvider(),
        );
    }

    #[DataProvider('roundTripProvider')]
    public function testRoundTrip(string $path, string $content): void
    {
        // Do a first pass to serialize the original content.
        $first = iterator_to_array(NFormatParser::parse($content));

        // Re-serialize the format.
        $lines = [];
        foreach ($first as $stmt) {
            $lines[] = NFormatSerializer::serialize($stmt[0], $stmt[1], $stmt[2], $stmt[3] ?? null);
        }

        $reencoded = implode("\n", $lines);

        // and parse it again.
        $second = iterator_to_array(NFormatParser::parse($reencoded));

        $this->assertSame(count($first), count($second), 'statement count must match after round-trip');
        for ($i = 0; $i < count($first); $i++) {
            $s1 = $first[$i];
            $s2 = $second[$i];
            $this->assertSame(count($s1), count($s2), 'statement ' . $i . ' size must match');
            for ($j = 0; $j < count($s1); $j++) {
                $msg = 'statement ' . $i . ' term ' . $j . ' must match after round-trip';
                if ($s1[$j] === null || $s2[$j] === null) {
                    $this->assertNull($s1[$j], $msg);
                    $this->assertNull($s2[$j], $msg);
                    continue;
                }

                $this->assertNotNull($s1[$j], $msg);
                $this->assertNotNull($s2[$j], $msg);

                $this->assertTrue($s1[$j]->equals($s2[$j]), $msg);
            }
        }
    }

    /** @return array<string, array{string, string}> */
    public static function emptyTriplesProvider(): array
    {
        return self::getFilesInDir(
            dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'rdf_tests' . DIRECTORY_SEPARATOR . 'ntriples' . DIRECTORY_SEPARATOR . 'empty',
            '*.nt',
        );
    }

    #[DataProvider('emptyTriplesProvider')]
    public function testEmptyTriples(string $path, string $content): void
    {
        $statements = NFormatParser::parse($content);

        $count = 0;
        foreach ($statements as $statement) {
            $count++;
        }

        $this->assertEquals(0, $count, 'should return no statements');
    }

    /** @return array<string, array{string, string}> */
    public static function badTriplesProvider(): array
    {
        return self::getFilesInDir(
            dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'rdf_tests' . DIRECTORY_SEPARATOR . 'ntriples' . DIRECTORY_SEPARATOR . 'bad',
            '*.nt',
        );
    }

    #[DataProvider('badTriplesProvider')]
    #[RequiresSetting('zend.assertions', '1')]
    public function testBadTriplesWithAssertions(string $path, string $content): void
    {
        $this->expectException(AssertionError::class);

        $statements = NFormatParser::parse($content);
        iterator_to_array($statements); // exhaust the iterator!
    }

    #[DataProvider('badTriplesProvider')]
    #[IgnoreDeprecations]
    #[RequiresSetting('zend.assertions', '0')]
    #[DoesNotPerformAssertions]
    public function testBadTriplesWithoutAssertions(string $path, string $content): void
    {
        // Without assertions, this should produce something.
        // We don't care what it is, just that it doesn't throw.
        $statements = NFormatParser::parse($content);
        iterator_to_array($statements); // exhaust the iterator!
    }

    /** @return array<string, array{string, string}> */
    public static function badQuadsProvider(): array
    {
        return self::getFilesInDir(
            dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'rdf_tests' . DIRECTORY_SEPARATOR . 'nquads' . DIRECTORY_SEPARATOR . 'bad',
            '*.nq',
        );
    }

    #[DataProvider('badQuadsProvider')]
    #[RequiresSetting('zend.assertions', '1')]
    public function testBadQuadsWithAssertions(string $path, string $content): void
    {
        $this->expectException(AssertionError::class);

        $statements = NFormatParser::parse($content);
        iterator_to_array($statements); // exhaust the iterator!
    }

    #[DataProvider('badQuadsProvider')]
    #[IgnoreDeprecations]
    #[RequiresSetting('zend.assertions', '0')]
    #[DoesNotPerformAssertions]
    public function testBadQuadsWithoutAssertions(string $path, string $content): void
    {
        // Without assertions, this should produce something.
        // We don't care what it is, just that it doesn't throw.
        $statements = NFormatParser::parse($content);
        iterator_to_array($statements); // exhaust the iterator!
    }
}
