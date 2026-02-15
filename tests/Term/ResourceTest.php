<?php

declare(strict_types=1);

namespace FancySparql\Tests\Term;

use DOMDocument;
use FancySparql\Term\Resource;
use FancySparql\Xml\XMLUtils;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
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
    #[DataProviderExternal(TermTest::class, 'resourceSerializationProvider')]
    public function testSerialize(
        Resource $resource,
        array $expectedJson,
        string $expectedXml,
    ): void {
        self::assertSame($expectedJson, $resource->jsonSerialize(), 'JSON serialization');

        $gotXML = XMLUtils::formatXML($resource->xmlSerialize(new DOMDocument()));
        self::assertSame($expectedXml, $gotXML, 'XML serialization');
    }

    /** @param ResourceElement $expectedJson */
    #[DataProviderExternal(TermTest::class, 'resourceSerializationProvider')]
    public function testDeserialize(
        Resource $resource,
        array $expectedJson,
        string $expectedXml,
    ): void {
        $resourceFromXml = Resource::deserializeXML(XMLUtils::parseAndGetRootNode($expectedXml));
        self::assertTrue($resourceFromXml->equals($resource), 'XML deserialize');

        $resourceFromJson = Resource::deserializeJSON($expectedJson);
        self::assertTrue($resourceFromJson->equals($resource), 'JSON deserialize');
    }

    #[DataProvider('deserializeInvalidXMLProvider')]
    public function testDeserializeInvalidXMLElement(string $xml, string $expectedMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        Resource::deserializeXML(XMLUtils::parseAndGetRootNode($xml));
    }

    /** @return array<string, array{string, string}> */
    public static function deserializeInvalidXMLProvider(): array
    {
        return [
            'invalid element name' => [
                "<?xml version=\"1.0\"?>\n<other>value</other>\n",
                'Invalid element name',
            ],
        ];
    }

    /** @param mixed[] $invalidData */
    #[DataProvider('deserializeInvalidJSONProvider')]
    public function testDeserializeInvalidJSON(array $invalidData, string $expectedMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);
        Resource::deserializeJSON($invalidData);
    }

    /** @return array<string, array{mixed[], string}> */
    public static function deserializeInvalidJSONProvider(): array
    {
        return [
            'invalid resource type' => [
                ['type' => 'literal', 'value' => 'https://example.com/foo'],
                'Invalid resource type',
            ],
            'missing type' => [
                ['value' => 'https://example.com/foo'],
                'Invalid resource type',
            ],
            'missing value' => [
                ['type' => 'uri'],
                'Invalid resource value',
            ],
            'invalid value type' => [
                ['type' => 'uri', 'value' => 123],
                'Invalid resource value',
            ],
        ];
    }
}
