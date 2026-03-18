<?php

declare(strict_types=1);

namespace FancyRDF\Tests\FancyRDF\Formats;

use FancyRDF\Dataset\Quad;
use FancyRDF\Formats\NFormatParser;
use FancyRDF\Formats\TrigSerializer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function basename;
use function file_get_contents;
use function glob;
use function iterator_to_array;
use function substr;
use function trim;

use const DIRECTORY_SEPARATOR;

/** @phpstan-import-type TripleOrQuadArray from Quad */
final class TrigSerializerTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     *
     * @throws RuntimeException
     */
    public static function turtleSerializeProvider(): array
    {
        $baseDir = __DIR__ . DIRECTORY_SEPARATOR . 'testdata' . DIRECTORY_SEPARATOR . 'trig-serializer';

        $cases   = [];
        $ntFiles = glob($baseDir . DIRECTORY_SEPARATOR . 'triples-*.nt');
        if ($ntFiles === false) {
            throw new RuntimeException('Failed to glob nt files in ' . $baseDir);
        }

        foreach ($ntFiles as $ntFile) {
            $baseName = basename($ntFile);
            $name     = substr($baseName, 0, -3);
            $ttlFile  = $baseDir . DIRECTORY_SEPARATOR . $name . '.ttl';

            $cases[$name] = [$ntFile, $ttlFile];
        }

        return $cases;
    }

    /**
     * @return array<string, array{string, string}>
     *
     * @throws RuntimeException
     */
    public static function trigSerializeProvider(): array
    {
        $baseDir = __DIR__ . DIRECTORY_SEPARATOR . 'testdata' . DIRECTORY_SEPARATOR . 'trig-serializer';

        $cases   = [];
        $nqFiles = glob($baseDir . DIRECTORY_SEPARATOR . 'graphs-*.nq');
        if ($nqFiles === false) {
            throw new RuntimeException('Failed to glob nq files in ' . $baseDir);
        }

        foreach ($nqFiles as $nqFile) {
            $baseName = basename($nqFile);
            $name     = substr($baseName, 0, -3);
            $trigFile = $baseDir . DIRECTORY_SEPARATOR . $name . '.trig';

            $cases[$name] = [$nqFile, $trigFile];
        }

        return $cases;
    }

    /**
     * @param string $ntFile  Path to the input N-Triples file.
     * @param string $ttlFile Path to the expected Turtle file.
     */

    /** @throws InvalidArgumentException */
    #[DataProvider('turtleSerializeProvider')]
    public function testTurtleSerialize(string $ntFile, string $ttlFile): void
    {
        $ntContents = file_get_contents($ntFile);
        self::assertNotFalse($ntContents, 'Failed to read input file: ' . $ntFile);

        $parser     = new NFormatParser();
        $statements = iterator_to_array($parser->parse($ntContents));

        $serializer = new TrigSerializer(false);

        foreach ($statements as $statement) {
            self::assertTrue(Quad::isTriple($statement));
            $serializer->writeQuad($statement);
        }

        $serializer->close();

        $actual = $serializer->getResult();

        $expected = file_get_contents($ttlFile);
        self::assertNotFalse($expected, 'Failed to read expected file: ' . $ttlFile);

        self::assertSame(
            trim($expected),
            trim($actual),
        );
    }

    /**
     * @param string $nqFile   Path to the input N-Quads file.
     * @param string $trigFile Path to the expected TriG file.
     */

    /** @throws InvalidArgumentException */
    #[DataProvider('trigSerializeProvider')]
    public function testTrigSerialize(string $nqFile, string $trigFile): void
    {
        $nqContents = file_get_contents($nqFile);
        self::assertNotFalse($nqContents, 'Failed to read input file: ' . $nqFile);

        $parser     = new NFormatParser();
        $statements = iterator_to_array($parser->parse($nqContents));

        $serializer = new TrigSerializer(true);

        foreach ($statements as $statement) {
            $serializer->writeQuad($statement);
        }

        $serializer->close();

        $actual = $serializer->getResult();

        $expected = file_get_contents($trigFile);
        self::assertNotFalse($expected, 'Failed to read expected file: ' . $trigFile);

        self::assertSame(
            trim($expected),
            trim($actual),
        );
    }

    /** @throws InvalidArgumentException */
    public function testTurtleRejectsGraphs(): void
    {
        $serializer = new TrigSerializer(false);

        $parser = new NFormatParser();
        $quad   = iterator_to_array($parser->parse('<http://example.org/s> <http://example.org/p> "o" <http://example.org/g> .'))[0] ?? null;
        self::assertNotNull($quad);

        $this->expectException(InvalidArgumentException::class);
        $serializer->writeQuad($quad);
    }
}
