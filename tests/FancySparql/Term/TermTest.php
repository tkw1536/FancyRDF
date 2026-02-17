<?php

declare(strict_types=1);

namespace FancySparql\Tests\FancySparql\Term;

use FancySparql\Term\Literal;
use FancySparql\Term\Resource;
use FancySparql\Term\Term;
use FancySparql\Xml\XMLUtils;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function gmp_sign;

/**
 * @phpstan-import-type LiteralElement from Literal
 * @phpstan-import-type ResourceElement from Resource
 */
final class TermTest extends TestCase
{
    /** @param LiteralElement|ResourceElement $expectedJson */
    #[DataProvider('termDeserializationProvider')]
    public function testDeserializeJSONAndXML(Literal|Resource $term, array $expectedJson, string $expectedXml): void
    {
        $fromJson = Term::deserializeJSON($expectedJson);
        self::assertTrue($fromJson->equals($term), 'JSON deserialize');

        $fromXml = Term::deserializeXML(XMLUtils::parseAndGetRootNode($expectedXml));
        self::assertTrue($fromXml->equals($term), 'XML deserialize');
    }

    /** @return array<string, array{Literal|Resource, LiteralElement|ResourceElement, string}> */
    public static function termDeserializationProvider(): array
    {
        $cases = [];
        foreach (self::literalSerializationProvider() as $name => $row) {
            $cases['literal: ' . $name] = $row;
        }

        foreach (self::resourceSerializationProvider() as $name => $row) {
            $cases['resource: ' . $name] = $row;
        }

        return $cases;
    }

    /** @return array<string, array{Literal, LiteralElement, string}> */
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

    /** @return array<string, array{Resource, ResourceElement, string}> */
    public static function resourceSerializationProvider(): array
    {
        return [
            'URI' => [
                new Resource('https://example.com/foo'),
                ['type' => 'uri', 'value' => 'https://example.com/foo'],
                '<uri>https://example.com/foo</uri>',
            ],
            'URI alternative' => [
                new Resource('https://example.com/id'),
                ['type' => 'uri', 'value' => 'https://example.com/id'],
                '<uri>https://example.com/id</uri>',
            ],
            'blank node' => [
                new Resource('_:b1'),
                ['type' => 'bnode', 'value' => 'b1'],
                '<bnode>b1</bnode>',
            ],
            'blank node alternative' => [
                new Resource('_:n0'),
                ['type' => 'bnode', 'value' => 'n0'],
                '<bnode>n0</bnode>',
            ],
        ];
    }

    /** @return list<Term> */
    private function getTerms(): array
    {
        return [
            // IRIs
            new Resource('https://example.com/foo'),
            new Resource('https://example.com/id'),

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
            new Resource('_:b1'),
            new Resource('_:n0'),
        ];
    }

    public function testEquals(): void
    {
        $terms = $this->getTerms();
        foreach ($terms as $i => $term) {
            foreach ($terms as $j => $other) {
                $shouldEquals = $i === $j;
                self::assertSame($shouldEquals, $term->equals($other, true), 'Term ' . $i . ' equals ' . $j);
            }
        }
    }

    public function testCompare(): void
    {
        $terms = $this->getTerms();
        foreach ($terms as $i => $term) {
            foreach ($terms as $j => $other) {
                $shouldCompare = $i - $j;
                $gotCompare    = $term->compare($other);

                self::assertSame(
                    gmp_sign($shouldCompare),
                    gmp_sign($gotCompare),
                    'Term ' . $i . ' compares ' . $j,
                );
            }
        }
    }
}
