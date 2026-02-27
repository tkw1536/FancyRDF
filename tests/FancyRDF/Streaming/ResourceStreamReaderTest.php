<?php

declare(strict_types=1);

namespace FancyRDF\Tests\FancyRDF\Streaming;

use FancyRDF\Streaming\ResourceStreamReader;
use Generator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function count;
use function fclose;
use function fopen;
use function fwrite;
use function is_resource;
use function mb_str_split;
use function rewind;
use function strlen;

final class ResourceStreamReaderTest extends TestCase
{
    /** @return resource */
    private static function openString(string $input)
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

    #[TestWith([1])]
    #[TestWith([2])]
    #[TestWith([3])]
    #[TestWith([4])]
    #[TestWith([5])]
    #[TestWith([6])]
    #[TestWith([7])]
    #[TestWith([8])]
    #[TestWith([9])]
    #[TestDox('peek, peekPrefix and consume behave correctly over a memory stream with chunk size $size')]
    public function testPeekPeekPrefixAndConsume(int $size): void
    {
        $stream = self::openString('Hello, World!');

        try {
            $reader = new ResourceStreamReader($stream, $size);

            // Peek at first character (offset 0)
            self::assertSame('H', $reader->peek());
            self::assertSame('H', $reader->peek(0));

            // Peek at offset 1 and 2 without consuming
            self::assertSame('e', $reader->peek(1));
            self::assertSame('l', $reader->peek(2));
            self::assertSame('H', $reader->peek(0));

            // peekPrefix: match at start
            self::assertTrue($reader->peekPrefix('Hello'));
            self::assertTrue($reader->peekPrefix('Hel'));
            self::assertFalse($reader->peekPrefix('World'));
            self::assertFalse($reader->peekPrefix('Hello, World!!'));

            // peekPrefix: case-sensitive
            self::assertFalse($reader->peekPrefix('hello'));
            self::assertTrue($reader->peekPrefix('HELLO', true));

            // Consume "Hello"
            self::assertSame('Hello', $reader->consume(5));
            self::assertSame(',', $reader->peek(0));
            self::assertSame(' ', $reader->peek(1));

            // peekPrefix after consume
            self::assertTrue($reader->peekPrefix(', World'));
            self::assertFalse($reader->peekPrefix('Hello'));

            // Consume ", "
            self::assertSame(', ', $reader->consume(2));
            self::assertSame('W', $reader->peek());

            // Consume "World"
            self::assertSame('World', $reader->consume(5));
            self::assertSame('!', $reader->peek(0));

            // Consume last character
            self::assertSame('!', $reader->consume(1));

            // At end: peek returns null
            self::assertNull($reader->peek(0));
            self::assertNull($reader->peek());

            // At end: peekPrefix with non-empty string is false
            self::assertFalse($reader->peekPrefix('x'));

            // Consume past end returns remaining (empty)
            self::assertSame('', $reader->consume(10));
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public static function multiByteProvider(): Generator
    {
        foreach (
            [
            // 1 byte
                'f',
                '*',
                'k',
            // 2 bytes
                'é',
                '£',
            // 3 bytes
                '€',
                '中',
            // 4 bytes
                '😀',
                '𝄞',

            // all of the above
                'f*ké£€中😀𝄞',
            ] as $input
        ) {
            for ($i = 1; $i < strlen($input); $i++) {
                yield ['chunkSize' => $i, 'input' => $input];
            }
        }
    }

    #[TestDox('peek and consume handle UTF-8 runes of size > 1 byte with chunk size $chunkSize')]
    #[DataProvider('multiByteProvider')]
    public function testPeekAndConsumeWithMultiByteUtf8Runes(int $chunkSize, string $input): void
    {
        $stream = self::openString($input);

        try {
            $reader     = new ResourceStreamReader($stream, $chunkSize);
            $codePoints = mb_str_split($input, 1, 'UTF-8');

            foreach ($codePoints as $cp) {
                self::assertSame($cp, $reader->peek(0), 'peek(0) should return next code point');
                self::assertSame($cp, $reader->consume(strlen($cp)), 'consume(byteLen) should return full code point');
            }

            self::assertNull($reader->peek(0));
            self::assertNull($reader->peek());
            self::assertSame('', $reader->consume(1));
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $stream2 = self::openString($input);
        try {
            // peekPrefix with multi-byte prefix (re-open same string)
            $reader2 = new ResourceStreamReader($stream2, $chunkSize);

            $firstCp = $codePoints[0];
            self::assertTrue($reader2->peekPrefix($firstCp));

            if (count($codePoints) >= 2) {
                $prefix2 = $codePoints[0] . $codePoints[1];
                self::assertTrue($reader2->peekPrefix($prefix2));
            }
        } finally {
            if (is_resource($stream2)) {
                fclose($stream2);
            }
        }
    }

    #[TestDox('peek with negative offset throws InvalidArgumentException')]
    public function testPeekNegativeOffsetThrows(): void
    {
        $stream = self::openString('x');

        try {
            $reader = new ResourceStreamReader($stream);

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('offset must be non-negative');

            $reader->peek(-1);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    #[TestDox('consume with negative bytes throws InvalidArgumentException')]
    public function testConsumeNegativeBytesThrows(): void
    {
        $stream = self::openString('x');

        try {
            $reader = new ResourceStreamReader($stream);

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('offset must be non-negative');

            $reader->consume(-1);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
