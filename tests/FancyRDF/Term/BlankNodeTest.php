<?php

declare(strict_types=1);

namespace FancyRDF\Tests\FancyRDF\Term;

use DOMDocument;
use FancyRDF\Term\BlankNode;
use FancyRDF\Xml\XMLUtils;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** @phpstan-import-type BlankNodeArray from BlankNode */
final class BlankNodeTest extends TestCase
{
    /** @param BlankNodeArray $expectedJson */
    #[DataProviderExternal(TermTest::class, 'blankNodeSerializationProvider')]
    #[TestDox('$_dataname correctly serializes to json')]
    public function testSerializeJson(
        BlankNode $blankNode,
        array $expectedJson,
        string $_expectedXml,
    ): void {
        self::assertSame($expectedJson, $blankNode->jsonSerialize(), 'JSON serialization');
    }

    /** @param BlankNodeArray $expectedJson */
    #[DataProviderExternal(TermTest::class, 'blankNodeSerializationProvider')]
    #[TestDox('$_dataname correctly serializes to xml')]
    public function testSerializeXml(
        BlankNode $blankNode,
        array $expectedJson,
        string $expectedXml,
    ): void {
        $gotXML = XMLUtils::formatXML($blankNode->xmlSerialize(new DOMDocument()));
        self::assertSame($expectedXml, $gotXML, 'XML serialization');
    }

    /** @param BlankNodeArray $expectedJson */
    #[DataProviderExternal(TermTest::class, 'blankNodeSerializationProvider')]
    #[TestDox('$_dataname correctly deserializes from xml')]
    public function testDeserializeXml(
        BlankNode $blankNode,
        array $expectedJson,
        string $expectedXml,
    ): void {
        $blankFromXml = BlankNode::deserializeXML(XMLUtils::parseAndGetRootNode($expectedXml));
        self::assertTrue($blankFromXml->equals($blankNode), 'XML deserialize');
    }

    /** @param BlankNodeArray $expectedJson */
    #[DataProviderExternal(TermTest::class, 'blankNodeSerializationProvider')]
    #[TestDox('$_dataname correctly deserializes from json')]
    public function testDeserializeJson(
        BlankNode $blankNode,
        array $expectedJson,
        string $expectedXml,
    ): void {
        $blankFromJson = BlankNode::deserializeJSON($expectedJson);
        self::assertTrue($blankFromJson->equals($blankNode), 'JSON deserialize');
    }

    #[DataProvider('deserializeInvalidXMLProvider')]
    #[TestDox('$_dataname refuses to deserialize invalid xml')]
    public function testDeserializeInvalidXMLElement(string $xml, string $expectedMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        BlankNode::deserializeXML(XMLUtils::parseAndGetRootNode($xml));
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
    #[TestDox('$_dataname refuses to deserialize invalid json')]
    public function testDeserializeInvalidJSON(array $invalidData, string $expectedMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);
        BlankNode::deserializeJSON($invalidData);
    }

    /** @return array<string, array{mixed[], string}> */
    public static function deserializeInvalidJSONProvider(): array
    {
        return [
            'invalid resource type' => [
                ['type' => 'uri', 'value' => 'b1'],
                'Invalid resource type',
            ],
            'missing type' => [
                ['value' => 'b1'],
                'Invalid resource type',
            ],
            'missing value' => [
                ['type' => 'bnode'],
                'Blank node identifier must be a non-empty string',
            ],
            'invalid value type' => [
                ['type' => 'bnode', 'value' => 123],
                'Blank node identifier must be a non-empty string',
            ],
        ];
    }
}
