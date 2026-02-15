<?php

declare(strict_types=1);

namespace FancySparql\Tests\Term;

use FancySparql\Term\Literal;
use FancySparql\Term\Resource;
use FancySparql\Term\Term;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

use function simplexml_load_string;

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

        $element = simplexml_load_string($expectedXml);
        self::assertInstanceOf(SimpleXMLElement::class, $element);
        $fromXml = Term::deserializeXML($element);

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
                "<?xml version=\"1.0\"?>\n<literal>hello</literal>\n",
            ],
            'literal with language' => [
                new Literal('hello', 'en'),
                ['type' => 'literal', 'value' => 'hello', 'language' => 'en'],
                "<?xml version=\"1.0\"?>\n<literal xml:lang=\"en\">hello</literal>\n",
            ],
            'literal with datatype' => [
                new Literal('42', null, 'http://www.w3.org/2001/XMLSchema#integer'),
                [
                    'type' => 'literal',
                    'value' => '42',
                    'datatype' => 'http://www.w3.org/2001/XMLSchema#integer',
                ],
                "<?xml version=\"1.0\"?>\n<literal datatype=\"http://www.w3.org/2001/XMLSchema#integer\">42</literal>\n",
            ],
            'empty value' => [
                new Literal(''),
                ['type' => 'literal', 'value' => ''],
                "<?xml version=\"1.0\"?>\n<literal/>\n",
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
                "<?xml version=\"1.0\"?>\n<uri>https://example.com/foo</uri>\n",
            ],
            'URI alternative' => [
                new Resource('https://example.com/id'),
                ['type' => 'uri', 'value' => 'https://example.com/id'],
                "<?xml version=\"1.0\"?>\n<uri>https://example.com/id</uri>\n",
            ],
            'blank node' => [
                new Resource('_:b1'),
                ['type' => 'bnode', 'value' => 'b1'],
                "<?xml version=\"1.0\"?>\n<bnode>b1</bnode>\n",
            ],
            'blank node alternative' => [
                new Resource('_:n0'),
                ['type' => 'bnode', 'value' => 'n0'],
                "<?xml version=\"1.0\"?>\n<bnode>n0</bnode>\n",
            ],
        ];
    }

    public function testEquals(): void
    {
        // A set of distinct terms.
        // This test checks that a term is only equal to itself.
        $terms = [
            new Literal('hello'),
            new Literal('hello', 'en'),
            new Literal('hello', null, 'http://www.w3.org/2001/XMLSchema#integer'),
            new Literal(''),
            new Resource('https://example.com/foo'),
            new Resource('https://example.com/id'),
            new Resource('_:b1'),
            new Resource('_:n0'),
        ];

        foreach ($terms as $i => $term) {
            foreach ($terms as $j => $other) {
                $shouldEquals = $i === $j;
                self::assertSame($shouldEquals, $term->equals($other), 'Term ' . $i . ' equals ' . $j);
            }
        }
    }
}
