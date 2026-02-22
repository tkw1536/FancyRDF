<?php

declare(strict_types=1);

namespace FancyRDF\Tests\FancyRDF\Term;

use FancyRDF\Term\BlankNode;
use FancyRDF\Term\Iri;
use FancyRDF\Term\Literal;
use FancyRDF\Term\Term;
use FancyRDF\Xml\XMLUtils;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

use function var_export;

/**
 * @phpstan-import-type LiteralArray from Literal
 * @phpstan-import-type IRIArray from Iri
 * @phpstan-import-type BlankNodeArray from BlankNode
 */
final class TermTest extends TestCase
{
    /** @return array<string, array{Literal|Iri|BlankNode, LiteralArray|IRIArray|BlankNodeArray, string}> */
    public static function termDeserializationProvider(): array
    {
        $cases = [];
        foreach (self::literalSerializationProvider() as $name => $row) {
            $cases['literal: ' . $name] = $row;
        }

        foreach (self::iriSerializationProvider() as $name => $row) {
            $cases['resource: ' . $name] = $row;
        }

        foreach (self::blankNodeSerializationProvider() as $name => $row) {
            $cases['blank node: ' . $name] = $row;
        }

        return $cases;
    }

    /** @return array<string, array{Literal, LiteralArray, string}> */
    public static function literalSerializationProvider(): array
    {
        return [
            'plain literal' => [
                new Literal('hello'),
                ['type' => 'literal', 'value' => 'hello'],
                '<literal>hello</literal>',
            ],
            'literal with language' => [
                new Literal('hello', 'en'),
                ['type' => 'literal', 'value' => 'hello', 'language' => 'en'],
                '<literal xml:lang="en">hello</literal>',
            ],
            'literal with datatype' => [
                new Literal('42', null, 'http://www.w3.org/2001/XMLSchema#integer'),
                [
                    'type' => 'literal',
                    'value' => '42',
                    'datatype' => 'http://www.w3.org/2001/XMLSchema#integer',
                ],
                '<literal datatype="http://www.w3.org/2001/XMLSchema#integer">42</literal>',
            ],
            'empty value' => [
                new Literal(''),
                ['type' => 'literal', 'value' => ''],
                '<literal/>',
            ],
        ];
    }

    /** @return array<string, array{Iri, IRIArray, string}> */
    public static function iriSerializationProvider(): array
    {
        return [
            'foo iri' => [
                new Iri('https://example.com/foo'),
                ['type' => 'uri', 'value' => 'https://example.com/foo'],
                '<uri>https://example.com/foo</uri>',
            ],
            'id iri' => [
                new Iri('https://example.com/id'),
                ['type' => 'uri', 'value' => 'https://example.com/id'],
                '<uri>https://example.com/id</uri>',
            ],
        ];
    }

    /** @return array<string, array{BlankNode, BlankNodeArray, string}> */
    public static function blankNodeSerializationProvider(): array
    {
        return [
            'b1 blank node' => [
                new BlankNode('b1'),
                ['type' => 'bnode', 'value' => 'b1'],
                '<bnode>b1</bnode>',
            ],
            'n0 blank node' => [
                new BlankNode('n0'),
                ['type' => 'bnode', 'value' => 'n0'],
                '<bnode>n0</bnode>',
            ],
        ];
    }

    /** @return list<Iri|Literal|BlankNode> a list of terms in increasing order */
    private function getTerms(): array
    {
        return [
            // IRIs
            new Iri('https://example.com/foo'),
            new Iri('https://example.com/id'),

            // String literals
            new Literal('abc'),
            new Literal('hello'),

            // Language tagged string literals
            new Literal('abc', 'de'),
            new Literal('hello', 'de'),
            new Literal('abc', 'en'),
            new Literal('hello', 'en'),

            // Typed literals
            new Literal('abc', null, 'https://example.com/datatype_a'),
            new Literal('hello', null, 'https://example.com/datatype_a'),
            new Literal('abc', null, 'https://example.com/datatype_b'),
            new Literal('hello', null, 'https://example.com/datatype_b'),

            // Blank nodes
            new BlankNode('b1'),
            new BlankNode('n0'),
        ];
    }

    /** @return array<string, array{Iri|BlankNode|Literal, Iri|BlankNode|Literal, array<string, string>, array<string, string>, bool}> */
    public static function unifyProvider(): array
    {
        $blank1 = new BlankNode('b1');
        $blank2 = new BlankNode('b2');
        $blankX = new BlankNode('x');

        $iri  = new Iri('https://example.com/foo');
        $iri2 = new Iri('https://example.com/foo2');

        $lit  = new Literal('foo');
        $lit2 = new Literal('bar');

        return [
            // IRIs only unify with themselves ...
            'no mappings: IRI vs IRI' => [$iri, $iri, [], [], true],
            'no mappings: IRI vs different IRI' => [$iri, $iri2, [], [], false],
            'no mappings: IRI vs Literal' => [$iri, $lit, [], [], false],
            'no mappings: IRI vs BlankNode' => [$iri, $blank1, [], [], false],

            // and they maintain state ...
            'with mappings: IRI vs IRI' => [$iri, $iri, ['a' => 'b'], ['a' => 'b'], true],
            'with mappings: IRI vs different IRI' => [$iri, $iri2, ['a' => 'b'], ['a' => 'b'], false],
            'with mappings: IRI vs Literal' => [$iri, $lit, ['a' => 'b'], ['a' => 'b'], false],
            'with mappings: IRI vs BlankNode' => [$iri, $blank1, ['a' => 'b'], ['a' => 'b'], false],

            // literals also only unify with themselves ...
            'no mappings: Literal vs Literal' => [$lit, $lit, [], [], true],
            'no mappings: Literal vs different Literal' => [$lit, $lit2, [], [], false],
            'no mappings: Literal vs IRI' => [$lit, $iri, [], [], false],
            'no mappings: Literal vs BlankNode' => [$lit, $blank1, [], [], false],

            // and they also maintain state ...
            'with mappings: Literal vs Literal' => [$lit, $lit, ['a' => 'b'], ['a' => 'b'], true],
            'with mappings: Literal vs different Literal' => [$lit, $lit2, ['a' => 'b'], ['a' => 'b'], false],
            'with mappings: Literal vs IRI' => [$lit, $iri, ['a' => 'b'], ['a' => 'b'], false],
            'with mappings: Literal vs BlankNode' => [$lit, $blank1, ['a' => 'b'], ['a' => 'b'], false],

            // blank nodes actual do unify work!
            'blank vs Literal' => [$blank1, $lit, [], [], false],
            'blank vs IRI' => [$blank1, $iri, [], [], false],
            'blank vs blank, no partial' => [$blank1, $blankX, [], ['b1' => 'x'], true],
            'blank vs blank, valid existing mapping' => [$blank1, $blankX, ['b1' => 'x'], ['b1' => 'x'], true],
            'blank vs blank, invalid existing mapping' => [$blank1, $blankX, ['b1' => 'other'], ['b1' => 'other'], false],
            'blank vs blank, them already in partial' => [$blank2, $blankX, ['b1' => 'x'], ['b1' => 'x'], false],
            'blank vs same blank' => [$blank1, $blank1, [], ['b1' => 'b1'], true],
        ];
    }

    /** @param LiteralArray|IRIArray|BlankNodeArray $expectedJson */
    #[DataProvider('termDeserializationProvider')]
    #[TestDox('$_dataname correctly deserializes xml')]
    public function testDeserializeJSON(Literal|Iri|BlankNode $term, array $expectedJson, string $expectedXml): void
    {
        $fromJson = Term::deserializeJSON($expectedJson);
        self::assertTrue($fromJson->equals($term), 'JSON deserialize' . var_export($expectedJson, true));
    }

    /** @param LiteralArray|IRIArray|BlankNodeArray $expectedJson */
    #[DataProvider('termDeserializationProvider')]
    #[TestDox('$_dataname correctly deserializes json')]
    public function testDeserializeXML(Literal|Iri|BlankNode $term, array $expectedJson, string $expectedXml): void
    {
        $fromXml = Term::deserializeXML(XMLUtils::parseAndGetRootNode($expectedXml));
        self::assertTrue($fromXml->equals($term), 'XML deserialize');
    }

    #[TestDox('only equal terms are actually literally equal')]
    public function testLiteralEquals(): void
    {
        $terms = $this->getTerms();
        foreach ($terms as $i => $term) {
            foreach ($terms as $j => $other) {
                $shouldEquals = $i === $j;
                self::assertSame(
                    $shouldEquals,
                    $term->equals($other, true),
                    'Term ' . $i . ' equals ' . $j,
                );
            }
        }
    }

    #[TestDox('lexically compared terms are ordered correctly')]
    public function testCompare(): void
    {
        $terms = $this->getTerms();
        foreach ($terms as $i => $term) {
            foreach ($terms as $j => $other) {
                $shouldCompare = $i - $j;
                $gotCompare    = $term->compare($other);

                self::assertSame(
                    self::sign($shouldCompare),
                    self::sign($gotCompare),
                    'Term ' . $i . ' compares ' . $j,
                );
            }
        }
    }

    private static function sign(int $value): int
    {
        // Use gmp_sign would introduce an extra dependency on ext-gmp.
        return match (true) {
            $value > 0 => 1,
            $value < 0 => -1,
            default => 0,
        };
    }

    /**
     * @param array<string, string> $partialIn
     * @param array<string, string> $partialOut
     */
    #[DataProvider('unifyProvider')]
    #[TestDox('$_dataname literally unifies correctly')]
    public function testUnify(Iri|BlankNode|Literal $left, Iri|BlankNode|Literal $right, array $partialIn, array $partialOut, bool $expected): void
    {
        $partial = $partialIn;
        $result  = $left->unify($right, $partial);

        self::assertSame($expected, $result, 'Return value');
        self::assertSame($partialOut, $partial, 'Mapping after call');
    }
}
