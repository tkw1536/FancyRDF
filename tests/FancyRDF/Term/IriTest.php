<?php

declare(strict_types=1);

namespace FancyRDF\Tests\FancyRDF\Term;

use DOMDocument;
use FancyRDF\Term\Iri;
use FancyRDF\Xml\XMLUtils;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** @phpstan-import-type IRIArray from Iri */
final class IriTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function deserializeInvalidXMLProvider(): array
    {
        return [
            'invalid element name' => [
                XMLUtils::XML_DECLARATION . "<other>value</other>\n",
                'Invalid element name',
            ],
            'empty IRI' => [
                XMLUtils::XML_DECLARATION . "<uri></uri>\n",
                'Empty IRI',
            ],
        ];
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
                'IRI reference string must be a non-empty string',
            ],
            'invalid value type' => [
                ['type' => 'uri', 'value' => 123],
                'IRI reference string must be a non-empty string',
            ],
        ];
    }

    /** @param IRIArray $expectedJson */
    #[DataProviderExternal(TermTest::class, 'iriSerializationProvider')]
    #[TestDox('$_dataname correctly serializes to json')]
    public function testSerializeJson(
        Iri $iri,
        array $expectedJson,
        string $expectedXml,
    ): void {
        self::assertSame($expectedJson, $iri->jsonSerialize(), 'JSON serialization');
    }

    /** @param IRIArray $expectedJson */
    #[DataProviderExternal(TermTest::class, 'iriSerializationProvider')]
    #[TestDox('$_dataname correctly serializes to xml')]
    public function testSerializeXml(
        Iri $iri,
        array $expectedJson,
        string $expectedXml,
    ): void {
        $gotXML = XMLUtils::formatXML($iri->xmlSerialize(new DOMDocument()));
        self::assertSame($expectedXml, $gotXML, 'XML serialization');
    }

    /** @param IRIArray $expectedJson */
    #[DataProviderExternal(TermTest::class, 'iriSerializationProvider')]
    #[TestDox('$_dataname correctly deserializes from xml')]
    public function testDeserializeXml(
        Iri $iri,
        array $expectedJson,
        string $expectedXml,
    ): void {
        $iriFromXml = Iri::deserializeXML(XMLUtils::parseAndGetRootNode($expectedXml));
        self::assertTrue($iriFromXml->equals($iri), 'XML deserialize');
    }

    /** @param IRIArray $expectedJson */
    #[DataProviderExternal(TermTest::class, 'iriSerializationProvider')]
    #[TestDox('$_dataname correctly deserializes from json')]
    public function testDeserializeJson(
        Iri $iri,
        array $expectedJson,
        string $expectedXml,
    ): void {
        $iriFromJson = Iri::deserializeJSON($expectedJson);
        self::assertTrue($iriFromJson->equals($iri), 'JSON deserialize');
    }

    #[DataProvider('deserializeInvalidXMLProvider')]
    #[TestDox('$_dataname refuses to deserialize invalid xml')]
    public function testDeserializeInvalidXMLElement(string $xml, string $expectedMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        Iri::deserializeXML(XMLUtils::parseAndGetRootNode($xml));
    }

    /** @param mixed[] $invalidData */
    #[DataProvider('deserializeInvalidJSONProvider')]
    #[TestDox('$_dataname refuses to deserialize invalid json')]
    public function testDeserializeInvalidJSON(array $invalidData, string $expectedMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);
        Iri::deserializeJSON($invalidData);
    }
}
