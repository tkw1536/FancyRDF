<?php

declare(strict_types=1);

namespace FancySparql\Tests\Term;

use DOMDocument;
use FancySparql\Term\Literal;
use FancySparql\Xml\XMLUtils;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

/** @phpstan-import-type LiteralElement from Literal */
final class LiteralTest extends TestCase
{
    /**
     * @param non-empty-string|null $language
     * @param non-empty-string|null $datatype
     */
    #[DataProvider('constructorProvider')]
    public function testConstructor(string $value, string|null $language, string|null $datatype, bool $expectValid): void
    {
        if (! $expectValid) {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Literal cannot have both language and datatype');
        }

        $literal = new Literal($value, $language, $datatype);
        if (! $expectValid) {
            return;
        }

        self::assertSame($value, $literal->value);
        self::assertSame($language, $literal->language);
        self::assertSame($datatype, $literal->datatype);
    }

    /** @return array<string, array{string, string|null, string|null, bool}> */
    public static function constructorProvider(): array
    {
        return [
            'value only' => ['hello', null, null, true],
            'value with language' => ['hello', 'en', null, true],
            'value with datatype' => ['42', null, 'http://www.w3.org/2001/XMLSchema#integer', true],
            'both language and datatype throws' => ['x', 'en', 'http://example.com/dt', false],
        ];
    }

    /** @param LiteralElement $expectedJson */
    #[DataProviderExternal(TermTest::class, 'literalSerializationProvider')]
    public function testSerialize(
        Literal $literal,
        array $expectedJson,
        string $expectedXml,
    ): void {
        self::assertSame($expectedJson, $literal->jsonSerialize(), 'JSON serialization');

        $gotXML = XMLUtils::formatXML($literal->xmlSerialize(new DOMDocument()));
        self::assertSame($expectedXml, $gotXML, 'XML serialization');
    }

    /** @param LiteralElement $expectedJson */
    #[DataProviderExternal(TermTest::class, 'literalSerializationProvider')]
    public function testDeserialize(
        Literal $literal,
        array $expectedJson,
        string $expectedXml,
    ): void {
        $literalFromXml = Literal::deserializeXML(XMLUtils::parseAndGetRootNode($expectedXml));
        self::assertTrue($literalFromXml->equals($literal), 'XML deserialize');

        $literalFromJson = Literal::deserializeJSON($expectedJson);
        self::assertTrue($literalFromJson->equals($literal), 'JSON deserialize');
    }

    #[DataProvider('deserializeInvalidXMLProvider')]
    public function testDeserializeInvalidXMLElement(string $xml, string $expectedMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        Literal::deserializeXML(XMLUtils::parseAndGetRootNode($xml));
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
        Literal::deserializeJSON($invalidData);
    }

    /** @return array<string, array{mixed[], string}> */
    public static function deserializeInvalidJSONProvider(): array
    {
        return [
            'missing value key' => [
                ['type' => 'literal'],
                'Value must be a string',
            ],
            'invalid language type' => [
                ['type' => 'literal', 'value' => 'foo', 'language' => 123],
                'Language must be a non-empty string',
            ],
            'invalid datatype type' => [
                ['type' => 'literal', 'value' => 'bar', 'datatype' => []],
                'Datatype must be a non-empty string',
            ],
        ];
    }
}
