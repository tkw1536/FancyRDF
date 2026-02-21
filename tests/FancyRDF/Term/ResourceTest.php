<?php

declare(strict_types=1);

namespace FancyRDF\Tests\FancyRDF\Term;

use DOMDocument;
use FancyRDF\Term\Literal;
use FancyRDF\Term\Resource;
use FancyRDF\Term\Term;
use FancyRDF\Xml\XMLUtils;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

/** @phpstan-import-type ResourceElement from Resource */
final class ResourceTest extends TestCase
{
    /** @param non-empty-string $uri */
    #[DataProvider('getBlankNodeIdProvider')]
    public function testGetBlankNodeId(string $uri, string|null $expectedId): void
    {
        $resource = new Resource($uri);
        self::assertSame($expectedId, $resource->getBlankNodeId());
    }

    /** @return array<string, array{non-empty-string, string|null}> */
    public static function getBlankNodeIdProvider(): array
    {
        return [
            'URI returns null' => ['https://example.com/s', null],
            'blank node returns id' => ['_:b1', 'b1'],
            'blank node alternative' => ['_:n0', 'n0'],
        ];
    }

    /** @return array<string, array{string, bool, bool}> */
    public static function isBlankNodeProvider(): array
    {
        return [
            'URI returns false' => ['https://example.com/s', false, true],
            'blank node returns true' => ['_:b1', true, false],
            'blank node alternative returns true' => ['_:n0', true, false],
        ];
    }

    /** @param non-empty-string $uri */
    #[DataProvider('isBlankNodeProvider')]
    public function testIsBlankNode(string $uri, bool $wantBlankNode, bool $wantGrounded): void
    {
        $resource = new Resource($uri);
        self::assertSame($wantBlankNode, $resource->isBlankNode());
        self::assertSame($wantGrounded, $resource->isGrounded());
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
                XMLUtils::XML_DECLARATION . "<other>value</other>\n",
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
                'Resource value must be a non-empty string',
            ],
            'invalid value type' => [
                ['type' => 'uri', 'value' => 123],
                'Resource value must be a non-empty string',
            ],
        ];
    }

    /**
     * @param array<string, string> $partialIn
     * @param array<string, string> $partialOut
     */
    #[DataProvider('unifyProvider')]
    public function testUnify(Resource $our, Term $other, array $partialIn, array $partialOut, bool $expected): void
    {
        $partial = $partialIn;
        $result  = $our->unify($other, $partial);

        self::assertSame($expected, $result, 'Return value');
        self::assertSame($partialOut, $partial, 'Mapping after call');
    }

    /** @return array<string, array{Resource, Term, array<string, string>, array<string, string>, bool}> */
    public static function unifyProvider(): array
    {
        $uriA   = new Resource('https://example.org/a');
        $uriB   = new Resource('https://example.org/b');
        $blank1 = new Resource('_:b1');
        $blank2 = new Resource('_:b2');
        $blankX = new Resource('_:x');
        $lit    = new Literal('foo');

        return [
            'URI vs Literal' => [$uriA, $lit, [], [], false],
            'URI vs same URI' => [$uriA, new Resource('https://example.org/a'), [], [], true],
            'URI vs different URI' => [$uriA, $uriB, [], [], false],
            'blank vs Literal' => [$blank1, $lit, [], [], false],
            'blank vs blank, no partial' => [$blank1, $blankX, [], ['b1' => 'x'], true],
            'blank vs blank, valid existing mapping' => [$blank1, $blankX, ['b1' => 'x'], ['b1' => 'x'], true],
            'blank vs blank, invalid existing mapping' => [$blank1, $blankX, ['b1' => 'other'], ['b1' => 'other'], false],
            'blank vs blank, them already in partial' => [$blank2, $blankX, ['b1' => 'x'], ['b1' => 'x'], false],
            'blank vs same blank' => [$blank1, new Resource('_:b1'), [], ['b1' => 'b1'], true],
        ];
    }
}
