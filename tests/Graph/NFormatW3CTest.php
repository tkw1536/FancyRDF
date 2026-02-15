<?php

declare(strict_types=1);

namespace FancySparql\Tests\Graph;

use Exception;
use FancySparql\Graph\NFormatParser;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function basename;
use function dirname;
use function file_get_contents;
use function glob;
use function json_encode;

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

        $triples = [];
        foreach ($statements as $statement) {
            $triples[] = $statement;
        }

        throw new Exception(json_encode($triples));
    }
}
