<?php

declare(strict_types=1);

namespace FancyRDF\Tests\FancyRDF\Results;

use DOMDocument;
use FancyRDF\Results\Result;
use FancyRDF\Term\Literal;
use FancyRDF\Term\Resource;
use FancyRDF\Xml\XMLUtils;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type LiteralElement from Literal
 * @phpstan-import-type ResourceElement from Resource
 */
final class ResultTest extends TestCase
{
    /**
     * @param array<string, Literal|Resource>               $bindings
     * @param array<string, ResourceElement|LiteralElement> $expectedJson
     */
    #[DataProvider('resultSerializationProvider')]
    public function testSerialize(
        array $bindings,
        array $expectedJson,
        string $expectedXml,
    ): void {
        $result = new Result($bindings);

        self::assertSame($expectedJson, $result->jsonSerialize(), 'JSON serialization');

        $gotXML = XMLUtils::formatXML($result->xmlSerialize(new DOMDocument()));
        self::assertSame($expectedXml, $gotXML, 'XML serialization');
    }

    /** @return array<string, array{array<string, Literal|Resource>, array<string, ResourceElement|LiteralElement>, string}> */
    public static function resultSerializationProvider(): array
    {
        return [
            'empty bindings' => [
                [],
                [],
                '<result/>',
            ],
            'single URI binding' => [
                ['s' => new Resource('https://example.com/s')],
                ['s' => ['type' => 'uri', 'value' => 'https://example.com/s']],
                '<result><binding name="s"><uri>https://example.com/s</uri></binding></result>',
            ],
            'single literal binding' => [
                ['label' => new Literal('A label')],
                ['label' => ['type' => 'literal', 'value' => 'A label']],
                '<result><binding name="label"><literal>A label</literal></binding></result>',
            ],
            'literal with language' => [
                ['lang' => new Literal('hello', 'en')],
                ['lang' => ['type' => 'literal', 'value' => 'hello', 'language' => 'en']],
                '<result><binding name="lang"><literal xml:lang="en">hello</literal></binding></result>',
            ],
            'two bindings' => [
                [
                    'x' => new Resource('https://example.com/foo'),
                    'label' => new Literal('A label'),
                ],
                [
                    'x' => ['type' => 'uri', 'value' => 'https://example.com/foo'],
                    'label' => ['type' => 'literal', 'value' => 'A label'],
                ],
                '<result><binding name="x"><uri>https://example.com/foo</uri></binding><binding name="label"><literal>A label</literal></binding></result>',
            ],
        ];
    }

    public function testGet(): void
    {
        $resource = new Resource('https://example.com/s');
        $literal  = new Literal('A label');
        $result   = new Result(['s' => $resource, 'label' => $literal]);

        self::assertSame($resource, $result->get('s'));
        self::assertSame($literal, $result->get('label'));
        self::assertNull($result->get('missing'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Binding 'missing' not found");
        $result->get('missing', false);
    }

    public function testGetLiteral(): void
    {
        $resource = new Resource('https://example.com/s');
        $literal  = new Literal('A label');
        $result   = new Result(['s' => $resource, 'label' => $literal]);

        self::assertSame($literal, $result->getLiteral('label'));
        self::assertNull($result->getLiteral('missing'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Binding 's' is not a Literal");
        $result->getLiteral('s');
    }

    public function testGetResource(): void
    {
        $resource = new Resource('https://example.com/s');
        $literal  = new Literal('A label');
        $result   = new Result(['s' => $resource, 'label' => $literal]);

        self::assertSame($resource, $result->getResource('s'));
        self::assertNull($result->getResource('missing'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Binding 'label' is not a Resource");
        $result->getResource('label');
    }
}
