<?php

declare(strict_types=1);

namespace FancyRDF\Tests\FancyRDF\Formats;

use AssertionError;
use FancyRDF\Dataset\Quad;
use FancyRDF\Formats\NFormatParser;
use FancyRDF\Formats\NFormatSerializer;
use FancyRDF\Term\Literal;
use FancyRDF\Term\Resource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresSetting;
use PHPUnit\Framework\TestCase;

final class NFormatTest extends TestCase
{
    /** @return array<string, array{Resource, Resource, Resource|Literal, Resource|null, string}> */
    public static function statementProvider(): array
    {
        return [
            'triple with graph null' => [
                new Resource('https://example.com/s'),
                new Resource('https://example.com/p'),
                new Resource('https://example.com/o'),
                null,
                '<https://example.com/s> <https://example.com/p> <https://example.com/o> .',
            ],
            'triple with literal object' => [
                new Resource('https://example.com/s'),
                new Resource('https://example.com/p'),
                new Literal('hello'),
                null,
                '<https://example.com/s> <https://example.com/p> "hello" .',
            ],
            'quad with graph (URI)' => [
                new Resource('https://example.com/s'),
                new Resource('https://example.com/p'),
                new Resource('https://example.com/o'),
                new Resource('https://example.com/g'),
                '<https://example.com/s> <https://example.com/p> <https://example.com/o> <https://example.com/g> .',
            ],
            'quad with graph (blank node)' => [
                new Resource('_:s'),
                new Resource('https://example.com/p'),
                new Literal('x'),
                new Resource('_:g'),
                '_:s <https://example.com/p> "x" _:g .',
            ],
            'triple with blank subject' => [
                new Resource('_:b0'),
                new Resource('https://example.com/p'),
                new Literal('lit'),
                null,
                '_:b0 <https://example.com/p> "lit" .',
            ],
        ];
    }

    #[DataProvider('statementProvider')]
    public function testSerialize(
        Resource $subject,
        Resource $predicate,
        Resource|Literal $object,
        Resource|null $graph,
        string $expected,
    ): void {
        self::assertSame($expected, NFormatSerializer::serialize($subject, $predicate, $object, $graph));
    }

    #[DataProvider('statementProvider')]
    public function testParseLine(
        Resource $subject,
        Resource $predicate,
        Resource|Literal $object,
        Resource|null $graph,
        string $line,
    ): void {
        $parsed = NFormatParser::parseLine($line);
        self::assertNotNull($parsed);
        self::assertTrue(Quad::equals($parsed, [$subject, $predicate, $object, $graph]));
    }

    public function testParseLineEmptyReturnsNull(): void
    {
        self::assertNull(NFormatParser::parseLine(''));
        self::assertNull(NFormatParser::parseLine('   '));
    }

    public function testParseLineCommentReturnsNull(): void
    {
        self::assertNull(NFormatParser::parseLine('# comment'));
        self::assertNull(NFormatParser::parseLine('  # rest is comment'));
    }

    #[RequiresSetting('zend.assertions', '1')]
    public function testParseLineInvalidThrows(): void
    {
        $this->expectException(AssertionError::class);
        $this->expectExceptionMessage('expected "." at end of statement at position 71');
        NFormatParser::parseLine('<https://example.com/s> <https://example.com/p> <https://example.com/o>');
    }
}
