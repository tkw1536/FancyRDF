<?php

declare(strict_types=1);

namespace FancyRDF\Tests\FancyRDF\Formats;

use FancyRDF\Dataset\Quad;
use FancyRDF\Exceptions\NonCompliantInputError;
use FancyRDF\Formats\NFormatParser;
use FancyRDF\Formats\NFormatSerializer;
use FancyRDF\Term\BlankNode;
use FancyRDF\Term\Iri;
use FancyRDF\Term\Literal;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class NFormatTest extends TestCase
{
    /**
     * @return array<string, array{Iri|BlankNode, Iri, Iri|Literal|BlankNode, Iri|BlankNode|null, string}>
     *
     * @throws InvalidArgumentException
     */
    public static function statementProvider(): array
    {
        return [
            'triple with graph null' => [
                new Iri('https://example.com/s'),
                new Iri('https://example.com/p'),
                new Iri('https://example.com/o'),
                null,
                '<https://example.com/s> <https://example.com/p> <https://example.com/o> .' . "\n",
            ],
            'triple with literal object' => [
                new Iri('https://example.com/s'),
                new Iri('https://example.com/p'),
                new Literal('hello'),
                null,
                '<https://example.com/s> <https://example.com/p> "hello" .' . "\n",
            ],
            'quad with graph (URI)' => [
                new Iri('https://example.com/s'),
                new Iri('https://example.com/p'),
                new Iri('https://example.com/o'),
                new Iri('https://example.com/g'),
                '<https://example.com/s> <https://example.com/p> <https://example.com/o> <https://example.com/g> .' . "\n",
            ],
            'quad with graph (blank node)' => [
                new BlankNode('s'),
                new Iri('https://example.com/p'),
                new Literal('x'),
                new BlankNode('g'),
                '_:s <https://example.com/p> "x" _:g .' . "\n",
            ],
            'triple with blank subject' => [
                new BlankNode('b0'),
                new Iri('https://example.com/p'),
                new Literal('lit'),
                null,
                '_:b0 <https://example.com/p> "lit" .' . "\n",
            ],
        ];
    }

    #[DataProvider('statementProvider')]
    public function testSerialize(
        Iri|BlankNode $subject,
        Iri $predicate,
        Iri|Literal|BlankNode $object,
        Iri|BlankNode|null $graph,
        string $expected,
    ): void {
        self::assertSame($expected, NFormatSerializer::serialize([$subject, $predicate, $object, $graph]));
    }

    /** @throws NonCompliantInputError */
    #[DataProvider('statementProvider')]
    public function testParseLine(
        Iri|BlankNode $subject,
        Iri $predicate,
        Iri|Literal|BlankNode $object,
        Iri|BlankNode|null $graph,
        string $line,
    ): void {
        $parsed = (new NFormatParser(true))->parseLine($line);
        self::assertNotNull($parsed);
        self::assertTrue(Quad::equals($parsed, [$subject, $predicate, $object, $graph]));

        $parsed = (new NFormatParser(false))->parseLine($line);
        self::assertNotNull($parsed);
        self::assertTrue(Quad::equals($parsed, [$subject, $predicate, $object, $graph]));
    }

    /** @throws NonCompliantInputError */
    #[TestWith([true])]
    #[TestWith([false])]
    public function testParseLineEmptyReturnsNull(bool $strict): void
    {
        self::assertNull((new NFormatParser($strict))->parseLine(''));
        self::assertNull((new NFormatParser($strict))->parseLine('   '));
    }

    /** @throws NonCompliantInputError */
    #[TestWith([true])]
    #[TestWith([false])]
    public function testParseLineCommentReturnsNull(bool $strict): void
    {
        self::assertNull((new NFormatParser($strict))->parseLine('# comment'));
        self::assertNull((new NFormatParser($strict))->parseLine('  # rest is comment'));
    }

    /** @throws NonCompliantInputError */
    public function testParseLineInvalidThrows(): void
    {
        $this->expectException(NonCompliantInputError::class);
        $this->expectExceptionMessage('expected "." at end of statement at position 71');
        (new NFormatParser(true))->parseLine('<https://example.com/s> <https://example.com/p> <https://example.com/o>');
    }
}
