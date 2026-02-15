<?php

declare(strict_types=1);

namespace FancySparql\Tests\Graph;

use Exception;
use FancySparql\Graph\NFormatParser;
use FancySparql\Graph\NFormatSerializer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
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
            $this->assertCount(3, $statement, 'should only return a triple');
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
            $this->assertCount(4, $statement, 'must return a quadruple');
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
                $this->assertTrue($s1[$j]->equals($s2[$j]), 'statement ' . $i . ' term ' . $j . ' must match after round-trip');
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
    public function testBadTriples(string $path, string $content): void
    {
        $this->expectException(InvalidArgumentException::class);

        $statements = NFormatParser::parse($content);
        iterator_to_array($statements); // exhause the iterator!
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
    public function testBadQuads(string $path, string $content): void
    {
        $this->expectException(InvalidArgumentException::class);

        $statements = NFormatParser::parse($content);
        iterator_to_array($statements); // exhause the iterator!
    }
}
