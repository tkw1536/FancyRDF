<?php

declare(strict_types=1);

namespace FancyRDF\Tests\FancyRDF\Formats;

use FancyRDF\Formats\NFormatSerializer;
use FancyRDF\Term\BlankNode;
use FancyRDF\Term\Iri;
use FancyRDF\Term\Literal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NFormatSerializerTest extends TestCase
{
    /** @return array<string, array{Iri|Literal|BlankNode, string}> */
    public static function serializeTermProvider(): array
    {
        return [
            // URIs without special characters
            'URI simple' => [
                new Iri('https://example.com/'),
                '<https://example.com/>',
            ],
            'URI with path' => [
                new Iri('https://example.com/foo/bar'),
                '<https://example.com/foo/bar>',
            ],
            'URI with fragment' => [
                new Iri('https://example.com/ns#thing'),
                '<https://example.com/ns#thing>',
            ],
            'URI with query' => [
                new Iri('https://example.com/search?q=hello'),
                '<https://example.com/search?q=hello>',
            ],

            // Blank nodes
            'blank node short id' => [
                new BlankNode('b0'),
                '_:b0',
            ],
            'blank node long id' => [
                new BlankNode('n3'),
                '_:n3',
            ],

            // Literals with datatype
            'literal plain' => [
                new Literal('hello'),
                '"hello"',
            ],
            'literal with datatype' => [
                new Literal('42', null, 'http://www.w3.org/2001/XMLSchema#integer'),
                '"42"^^<http://www.w3.org/2001/XMLSchema#integer>',
            ],
            'literal with datatype containing special chars' => [
                new Literal('x', null, 'http://example.com/a>b'),
                '"x"^^<http://example.com/a\u003Eb>',
            ],

            // Literals with language
            'literal with language' => [
                new Literal('hello', 'en'),
                '"hello"@en',
            ],
            'literal with language tag' => [
                new Literal('Bonjour', 'fr-FR'),
                '"Bonjour"@fr-FR',
            ],

            // Literals - characters needing escaping
            'literal with double quote' => [
                new Literal('say "hi"'),
                '"say \\"hi\\""',
            ],
            'literal with backslash' => [
                new Literal('back\\slash'),
                '"back\\\\slash"',
            ],
            'literal with tab' => [
                new Literal("a\tb"),
                '"a\\tb"',
            ],
            'literal with newline' => [
                new Literal("line1\nline2"),
                '"line1\\nline2"',
            ],
            'literal with carriage return' => [
                new Literal("a\rb"),
                '"a\\rb"',
            ],
            'literal with single quote' => [
                new Literal("it's"),
                "\"it\\'s\"",
            ],
            'literal with form feed' => [
                new Literal("a\fb"),
                '"a\\fb"',
            ],
            'literal with backspace' => [
                new Literal("a\x08b"),
                '"a\\bb"',
            ],
            'literal multiple escapes' => [
                new Literal("\\\"\n\t"),
                '"\\\\\\"\\n\\t"',
            ],

            // Unicode: 4-digit \u (BMP) and 8-digit \U (astral)
            'literal with BMP character' => [
                new Literal('café'),
                '"caf\u00E9"',
            ],
            'literal with astral character' => [
                new Literal('😀'),
                '"\\U0001F600"',
            ],
        ];
    }

    #[DataProvider('serializeTermProvider')]
    public function testSerializeTerm(Iri|Literal|BlankNode $term, string $expected): void
    {
        self::assertSame($expected, NFormatSerializer::serializeTerm($term));
    }
}
