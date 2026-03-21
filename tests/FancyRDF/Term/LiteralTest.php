<?php

declare(strict_types=1);

namespace FancyRDF\Tests\FancyRDF\Term;

use DOMDocument;
use DOMException;
use FancyRDF\Term\Datatype\LangString;
use FancyRDF\Term\Datatype\XSDBoolean;
use FancyRDF\Term\Datatype\XSDString;
use FancyRDF\Term\Iri;
use FancyRDF\Term\Literal;
use FancyRDF\Xml\XMLUtils;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function json_encode;

/** @phpstan-import-type LiteralArray from Literal */
final class LiteralTest extends TestCase
{
    /** @return array<string, array{string, string|null, string|null, string|null, bool}> */
    public static function constructorProvider(): array
    {
        return [
            'value only' => ['hello', null, null, XSDString::IRI, true],
            'value with default datatype' => ['hello', null, XSDString::IRI, XSDString::IRI, true],

            'value with only language tag' => ['hello', 'en', null, LangString::IRI, true],
            'value with language tag and valid datatype' => ['hello', 'en', LangString::IRI, LangString::IRI, true],

            'both language and datatype throws' => ['x', 'en', 'http://example.com/dt', null, false],
            'value with datatype' => ['42', null, 'http://www.w3.org/2001/XMLSchema#integer', 'http://www.w3.org/2001/XMLSchema#integer', true],
        ];
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

    #[TestWith([new Literal('hello', 'en', null), 'en'])]
    #[TestWith([new Literal('hello', null, new Iri(XSDString::IRI)), null])]
    #[TestWith([new Literal('1', null, new Iri(XSDBoolean::IRI)), new Iri(XSDBoolean::IRI)])]
    #[TestDox('$_dataname getTypeOrLanguage returns the correct type or language')]
    public function testGetTypeOrLanguage(Literal $literal, string|Iri|null $expected): void
    {
        $got = $literal->getTypeOrLanguage();
        self::assertSame(json_encode($expected), json_encode($got));
    }

    /**
     * @param non-empty-string|null $language
     * @param non-empty-string|null $datatype
     * @param non-empty-string|null $expectDatatype
     *
     * @throws InvalidArgumentException
     */
    #[DataProvider('constructorProvider')]
    #[TestDox('$_dataname allows instantiating valid literals')]
    public function testConstructor(string $value, string|null $language, string|null $datatype, string|null $expectDatatype, bool $expectValid): void
    {
        if (! $expectValid) {
            $this->expectException(InvalidArgumentException::class);
        }

        $literal = new Literal($value, $language, $datatype !== null ? new Iri($datatype) : null);
        if (! $expectValid) {
            return;
        }

        self::assertSame($value, $literal->lexical);
        self::assertSame($language, $literal->language);
        self::assertSame($expectDatatype, $literal->datatype->iri);
    }

    /** @param LiteralArray $expectedJson */
    #[DataProviderExternal(TermTest::class, 'literalSerializationProvider')]
    #[TestDox('$_dataname correctly serializes to json')]
    public function testSerializeJson(
        Literal $literal,
        array $expectedJson,
        string $expectedXml,
    ): void {
        self::assertSame($expectedJson, $literal->jsonSerialize(), 'JSON serialization');
    }

    /**
     * @param LiteralArray $expectedJson
     *
     * @throws RuntimeException
     * @throws DOMException
     */
    #[DataProviderExternal(TermTest::class, 'literalSerializationProvider')]
    #[TestDox('$_dataname correctly serializes to xml')]
    public function testSerializeXml(
        Literal $literal,
        array $expectedJson,
        string $expectedXml,
    ): void {
        $gotXML = XMLUtils::formatXML($literal->xmlSerialize(new DOMDocument()));
        self::assertSame($expectedXml, $gotXML, 'XML serialization');
    }

    /**
     * @param LiteralArray $expectedJson
     *
     * @throws RuntimeException
     * @throws InvalidArgumentException
     */
    #[DataProviderExternal(TermTest::class, 'literalSerializationProvider')]
    #[TestDox('$_dataname correctly deserializes from xml')]
    public function testDeserializeXml(
        Literal $literal,
        array $expectedJson,
        string $expectedXml,
    ): void {
        $literalFromXml = Literal::deserializeXML(XMLUtils::parseAndGetRootNode($expectedXml));
        self::assertTrue($literalFromXml->equals($literal), 'XML deserialize');
    }

    /**
     * @param LiteralArray $expectedJson
     *
     * @throws InvalidArgumentException
     */
    #[DataProviderExternal(TermTest::class, 'literalSerializationProvider')]
    #[TestDox('$_dataname correctly deserializes from json')]
    public function testDeserializeJson(
        Literal $literal,
        array $expectedJson,
        string $_expectedXml,
    ): void {
        $literalFromJson = Literal::deserializeJSON($expectedJson);
        self::assertTrue($literalFromJson->equals($literal), 'JSON deserialize');
    }

    /**
     * @throws RuntimeException
     * @throws InvalidArgumentException
     */
    #[DataProvider('deserializeInvalidXMLProvider')]
    #[TestDox('$_dataname refuses to deserialize invalid xml')]
    public function testDeserializeInvalidXMLElement(string $xml, string $expectedMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        Literal::deserializeXML(XMLUtils::parseAndGetRootNode($xml));
    }

    /**
     * @param mixed[] $invalidData
     *
     * @throws InvalidArgumentException
     */
    #[DataProvider('deserializeInvalidJSONProvider')]
    #[TestDox('$_dataname refuses to deserialize invalid json')]
    public function testDeserializeInvalidJSON(array $invalidData, string $expectedMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);
        Literal::deserializeJSON($invalidData);
    }
}
