<?php

declare(strict_types=1);

namespace FancySparql\Tests\Term;

use FancySparql\Term\Literal;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** @phpstan-import-type LiteralElement from Literal */
final class LiteralTest extends TestCase
{
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
    #[DataProvider('literalSerializationProvider')]
    public function testSerialize(
        string $value,
        string|null $language,
        string|null $datatype,
        array $expectedJson,
        string $expectedXml,
    ): void {
        $literal = new Literal($value, $language, $datatype);

        self::assertSame($expectedJson, $literal->jsonSerialize(), 'JSON serialization');
        self::assertSame($expectedXml, $literal->xmlSerialize(null)->asXML(), 'XML serialization');
    }

    /** @return array<string, array{string, string|null, string|null, LiteralElement, string}> */
    public static function literalSerializationProvider(): array
    {
        return [
            'plain literal' => [
                'hello',
                null,
                null,
                ['type' => 'literal', 'value' => 'hello'],
                "<?xml version=\"1.0\"?>\n<literal>hello</literal>\n",
            ],
            'literal with language' => [
                'hello',
                'en',
                null,
                ['type' => 'literal', 'value' => 'hello', 'language' => 'en'],
                "<?xml version=\"1.0\"?>\n<literal xml:lang=\"en\">hello</literal>\n",
            ],
            'literal with datatype' => [
                '42',
                null,
                'http://www.w3.org/2001/XMLSchema#integer',
                [
                    'type' => 'literal',
                    'value' => '42',
                    'datatype' => 'http://www.w3.org/2001/XMLSchema#integer',
                ],
                "<?xml version=\"1.0\"?>\n<literal datatype=\"http://www.w3.org/2001/XMLSchema#integer\">42</literal>\n",
            ],
            'empty value' => [
                '',
                null,
                null,
                ['type' => 'literal', 'value' => ''],
                "<?xml version=\"1.0\"?>\n<literal/>\n",
            ],
        ];
    }
}
