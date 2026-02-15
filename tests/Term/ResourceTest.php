<?php

declare(strict_types=1);

namespace FancySparql\Tests\Term;

use FancySparql\Term\Resource;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** @phpstan-import-type ResourceElement from Resource */
final class ResourceTest extends TestCase
{
    #[DataProvider('constructorProvider')]
    public function testConstructor(string $uri, bool $expectValid): void
    {
        if (! $expectValid) {
            $this->expectException(InvalidArgumentException::class);
        }

        $resource = new Resource($uri);
        if (! $expectValid) {
            return;
        }

        self::assertSame($uri, $resource->uri);
    }

    /** @return array<string, array{string, bool}> */
    public static function constructorProvider(): array
    {
        return [
            'valid URI' => ['https://example.com/foo', true],
            'valid blank node' => ['_:b1', true],
            'invalid blank node (empty id)' => ['_:', false],
            'invalid URI' => ['not-a-uri', false],
        ];
    }

    #[DataProvider('getBlankNodeIdProvider')]
    public function testGetBlankNodeId(string $uri, string|null $expectedId): void
    {
        $resource = new Resource($uri);
        self::assertSame($expectedId, $resource->getBlankNodeId());
    }

    /** @return array<string, array{string, string|null}> */
    public static function getBlankNodeIdProvider(): array
    {
        return [
            'URI returns null' => ['https://example.com/s', null],
            'blank node returns id' => ['_:b1', 'b1'],
            'blank node alternative' => ['_:n0', 'n0'],
        ];
    }

    /** @param ResourceElement $expectedJson */
    #[DataProvider('resourceSerializationProvider')]
    public function testSerialize(
        string $uri,
        array $expectedJson,
        string $expectedXml,
    ): void {
        $resource = new Resource($uri);

        self::assertSame($expectedJson, $resource->jsonSerialize(), 'JSON serialization');
        self::assertSame($expectedXml, $resource->xmlSerialize(null)->asXML(), 'XML serialization');
    }

    /** @return array<string, array{string, ResourceElement, string}> */
    public static function resourceSerializationProvider(): array
    {
        return [
            'URI' => [
                'https://example.com/foo',
                ['type' => 'uri', 'value' => 'https://example.com/foo'],
                "<?xml version=\"1.0\"?>\n<uri>https://example.com/foo</uri>\n",
            ],
            'URI alternative' => [
                'https://example.com/id',
                ['type' => 'uri', 'value' => 'https://example.com/id'],
                "<?xml version=\"1.0\"?>\n<uri>https://example.com/id</uri>\n",
            ],
            'blank node' => [
                '_:b1',
                ['type' => 'bnode', 'value' => 'b1'],
                "<?xml version=\"1.0\"?>\n<bnode>b1</bnode>\n",
            ],
            'blank node alternative' => [
                '_:n0',
                ['type' => 'bnode', 'value' => 'n0'],
                "<?xml version=\"1.0\"?>\n<bnode>n0</bnode>\n",
            ],
        ];
    }
}
