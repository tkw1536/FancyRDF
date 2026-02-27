<?php

declare(strict_types=1);

namespace FancyRDF\Tests\FancyRDF\Http;

use FancyRDF\Http\IteratorStream;
use Fiber;
use Generator;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use XMLReader;

use function fstat;
use function stream_get_contents;

final class IteratorStreamTest extends TestCase
{
    #[TestDox('stream_get_contents reads all chunked yields')]
    public function testStreamGetContentsWithChunkedYields(): void
    {
        $stream = IteratorStream::open(static function (): Generator {
            yield 'Hello';
            yield ', ';
            yield 'world';
            yield '!';
        });

        $contents = stream_get_contents($stream);

        self::assertSame('Hello, world!', $contents);
    }

    #[TestDox('stream opened with size parameter reports that size in fstat')]
    public function testStreamWithSizeParameterReturnsSizeInStat(): void
    {
        $expectedSize = 13;
        $stream       = IteratorStream::open(static function (): Generator {
            yield 'Hello';
            yield ', ';
            yield 'world';
            yield '!';
        }, $expectedSize);

        $stat = fstat($stream);

        self::assertIsArray($stat);
        self::assertSame($expectedSize, $stat['size']);
    }

    #[TestDox('openFiber with not-yet-started fiber yields string suspends and ignores non-strings')]
    public function testOpenFiberNotStartedSuspendsWithChunksAndIgnoresNonStrings(): void
    {
        $fiber = new Fiber(static function (): void {
            Fiber::suspend('Hello');
            Fiber::suspend(42);
            Fiber::suspend(' ');
            Fiber::suspend(null);
            Fiber::suspend('world');
            Fiber::suspend(true);
            Fiber::suspend('!');
        });

        self::assertFalse($fiber->isStarted());

        $stream   = IteratorStream::openFiber($fiber);
        $contents = stream_get_contents($stream);

        self::assertSame('Hello world!', $contents);
    }

    #[TestDox('XMLReader parses oddly chunked XML and reports element names')]
    public function testXmlReaderWithOddlyChunkedXml(): void
    {
        $stream = IteratorStream::open(static function (): Generator {
            yield '<r';
            yield 'oot>';
            yield '<f';
            yield 'oo/><bar';
            yield '></bar></root>';
        });

        $reader = XMLReader::fromStream($stream);
        $names  = [];

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }

            $names[] = $reader->name;
        }

        $reader->close();

        self::assertSame(['root', 'foo', 'bar'], $names);
    }
}
