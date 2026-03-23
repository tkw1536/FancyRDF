<?php

declare(strict_types=1);

namespace FancyRDF\Tests\FancyRDF\Formats;

use FancyRDF\Dataset\Quad;
use FancyRDF\Exceptions\NonCompliantInputError;
use FancyRDF\Formats\TrigParser;
use FancyRDF\Formats\TrigReader\TrigReader;
use FancyRDF\Streaming\ResourceStreamReader;
use FancyRDF\Tests\Support\IsomorphicAsDatasetsConstraint;
use FancyRDF\Tests\Support\LocalRdfTestCases;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

use function file_get_contents;
use function fopen;
use function fwrite;
use function iterator_to_array;
use function rewind;

use const DIRECTORY_SEPARATOR;

/** @phpstan-import-type TripleOrQuadArray from Quad */
final class TrigParserTest extends TestCase
{
    /**
     * @return resource
     *
     * @throws RuntimeException
     */
    private static function openString(string $input): mixed
    {
        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            throw new RuntimeException('Failed to open memory stream');
        }

        if (fwrite($stream, $input) === false) {
            throw new RuntimeException('Failed to write to memory stream');
        }

        if (rewind($stream) === false) {
            throw new RuntimeException('Failed to rewind memory stream');
        }

        return $stream;
    }

    /**
     * @return array<string, array{bool, string, string}>
     *
     * @throws RuntimeException
     */
    public static function turtleProvider(): array
    {
        $baseDir = __DIR__ . DIRECTORY_SEPARATOR . 'testdata' . DIRECTORY_SEPARATOR . 'rdf';
        $all     = LocalRdfTestCases::load($baseDir);

        $cases = [];

        foreach ($all as $name => $paths) {
            if ($paths['ttl'] === null || $paths['nt'] === null) {
                continue;
            }

            $cases['strict/' . $name] = [true, $paths['ttl'], $paths['nt']];
            $cases['loose/' . $name]  = [false, $paths['ttl'], $paths['nt']];
        }

        return $cases;
    }

    /**
     * @return array<string, array{string, string}>
     *
     * @throws RuntimeException
     */
    public static function trigProvider(): array
    {
        $baseDir = __DIR__ . DIRECTORY_SEPARATOR . 'testdata' . DIRECTORY_SEPARATOR . 'rdf';
        $all     = LocalRdfTestCases::load($baseDir);

        $cases = [];

        foreach ($all as $name => $paths) {
            if ($paths['trig'] === null || $paths['nq'] === null) {
                continue;
            }

            $cases[$name] = [$paths['trig'], $paths['nq']];
        }

        return $cases;
    }

    /**
     * @return array<string, array{string, class-string<Throwable>}>
     *
     * @throws RuntimeException
     */
    public static function invalidMixProvider(): array
    {
        $baseDir = __DIR__ . '/testdata/rdf';
        $all     = LocalRdfTestCases::load($baseDir);

        $cases = [];

        foreach ($all as $name => $paths) {
            if ($paths['nq'] === null) {
                continue;
            }

            $cases[$name] = [$paths['nq'], NonCompliantInputError::class];
        }

        return $cases;
    }

    /** @throws RuntimeException */
    #[DataProvider('turtleProvider')]
    #[TestDox('parses Turtle as triples: {_dataName}')]
    public function testParseTurtle(bool $strict, string $ttlFile, string $ntFile): void
    {
        $ttlSource = file_get_contents($ttlFile);
        self::assertNotFalse($ttlSource, 'Failed to read Turtle file: ' . $ttlFile);

        $stream = self::openString($ttlSource);
        $reader = new TrigReader($strict, new ResourceStreamReader($stream));
        $parser = new TrigParser($strict, $reader, false);

        $parsed = iterator_to_array($parser);

        $ntSource = file_get_contents($ntFile);
        self::assertNotFalse($ntSource, 'Failed to read N-Triples file: ' . $ntFile);

        $ntStream = self::openString($ntSource);
        $ntReader = new TrigReader($strict, new ResourceStreamReader($ntStream));
        $ntParser = new TrigParser($strict, $ntReader, false);
        $expected = iterator_to_array($ntParser);

        self::assertThat($parsed, new IsomorphicAsDatasetsConstraint($expected));
    }

    /** @throws RuntimeException */
    #[DataProvider('turtleProvider')]
    #[TestDox('parses Turtle as TriG (no graphs): {_dataName}')]
    public function testParseTurtleAsTrig(bool $strict, string $ttlFile, string $ntFile): void
    {
        $ttlSource = file_get_contents($ttlFile);
        self::assertNotFalse($ttlSource, 'Failed to read Turtle file: ' . $ttlFile);

        $stream = self::openString($ttlSource);
        $reader = new TrigReader($strict, new ResourceStreamReader($stream));
        $parser = new TrigParser($strict, $reader, true);

        $parsed = iterator_to_array($parser);

        $ntSource = file_get_contents($ntFile);
        self::assertNotFalse($ntSource, 'Failed to read N-Triples file: ' . $ntFile);

        $ntStream = self::openString($ntSource);
        $ntReader = new TrigReader($strict, new ResourceStreamReader($ntStream));
        $ntParser = new TrigParser($strict, $ntReader, true);
        $expected = iterator_to_array($ntParser);

        self::assertThat($parsed, new IsomorphicAsDatasetsConstraint($expected));
    }

    // #[DataProvider('trigProvider')]
    // #[TestDox('parses TriG as quads: {_dataName}')]
    // public function testParseTrig(string $trigFile, string $nqFile): void
    // {
    //     $trigSource = file_get_contents($trigFile);
    //     self::assertNotFalse($trigSource, 'Failed to read TriG file: ' . $trigFile);
    //
    //     $stream = self::openString($trigSource);
    //     $reader = new TrigReader(new ResourceStreamReader($stream));
    //     $parser = new TrigParser($reader, true);
    //
    //     $parsed = iterator_to_array($parser);
    //
    //     $nqSource = file_get_contents($nqFile);
    //     self::assertNotFalse($nqSource, 'Failed to read N-Quads file: ' . $nqFile);
    //
    //     $nqStream = self::openString($nqSource);
    //     $nqReader = new TrigReader(new ResourceStreamReader($nqStream));
    //     $nqParser = new TrigParser($nqReader, true);
    //     $expected = iterator_to_array($nqParser);
    //
    //     self::assertThat($parsed, new IsomorphicAsDatasetsConstraint($expected));
    // }

    // /** @param class-string<Throwable> $expectedException */
    // #[DataProvider('invalidMixProvider')]
    // #[TestDox('rejects N-Quads when not in TriG mode: {_dataName}')]
    // public function testInvalidMix(string $inputFile, string $expectedException): void
    // {
    //     $source = file_get_contents($inputFile);
    //     self::assertNotFalse($source, 'Failed to read input file: ' . $inputFile);
    //
    //     $stream = self::openString($source);
    //     $reader = new TrigReader(new ResourceStreamReader($stream));
    //
    //     $this->expectException($expectedException);
    //
    //     // Parsing N-Quads with isTrig=false should fail
    //     $parser = new TrigParser($reader, false);
    //     iterator_to_array($parser);
    // }
}
