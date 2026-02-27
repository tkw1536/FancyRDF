<?php

declare(strict_types=1);

namespace FancyRDF\Tests\FancyRDF\Results;

use DOMDocument;
use FancyRDF\Results\Result;
use FancyRDF\Term\BlankNode;
use FancyRDF\Term\Iri;
use FancyRDF\Term\Literal;
use FancyRDF\Xml\XMLUtils;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type LiteralArray from Literal
 * @phpstan-import-type IRIArray from Iri
 * @phpstan-import-type BlankNodeArray from BlankNode
 */
final class ResultTest extends TestCase
{
    /**
     * @param array<string, Literal|Iri|BlankNode>                $bindings
     * @param array<string, IRIArray|LiteralArray|BlankNodeArray> $expectedJson
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

    /** @return array<string, array{array<string, Literal|Iri|BlankNode>, array<string, IRIArray|LiteralArray|BlankNodeArray>, string}> */
    public static function resultSerializationProvider(): array
    {
        return [
            'empty bindings' => [
                [],
                [],
                '<result/>',
            ],
            'single URI binding' => [
                ['s' => new Iri('https://example.com/s')],
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
                    'x' => new Iri('https://example.com/foo'),
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

    // ==================================================
    // get
    // ==================================================

    /** tests the get method */
    public function testGet(): void
    {
        $iri       = new Iri('https://example.com/s');
        $literal   = new Literal('A label');
        $blankNode = new BlankNode('b1');
        $result    = new Result(['iri' => $iri, 'literal' => $literal, 'bnode' => $blankNode]);

        self::assertSame($iri, $result->get('iri'));
        self::assertSame($literal, $result->get('literal'));
        self::assertSame($blankNode, $result->get('bnode'));

        self::assertSame($iri, $result->get('iri', false));
        self::assertSame($literal, $result->get('literal', false));
        self::assertSame($blankNode, $result->get('bnode', false));

        self::assertNull($result->get('missing'));
    }

    public function testGetInvalidMissing(): void
    {
        $iri       = new Iri('https://example.com/s');
        $literal   = new Literal('A label');
        $blankNode = new BlankNode('b1');
        $result    = new Result(['iri' => $iri, 'literal' => $literal, 'bnode' => $blankNode]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Binding 'missing' not found");
        $result->get('missing', false);
    }

    // ==================================================
    // getResource
    // ==================================================

    /** tests the getResource method */
    public function testGetResource(): void
    {
        $iri       = new Iri('https://example.com/s');
        $literal   = new Literal('A label');
        $blankNode = new BlankNode('b1');
        $result    = new Result(['iri' => $iri, 'literal' => $literal, 'bnode' => $blankNode]);

        self::assertSame($iri, $result->getResource('iri'));
        self::assertSame($blankNode, $result->getResource('bnode'));

        self::assertSame($iri, $result->getResource('iri', false));
        self::assertSame($blankNode, $result->getResource('bnode', false));

        self::assertNull($result->getResource('missing'));
    }

    #[TestWith([true, false])]
    public function testGetResourceInvalidLiteral(bool $allowMissing): void
    {
        $iri       = new Iri('https://example.com/s');
        $literal   = new Literal('A label');
        $blankNode = new BlankNode('b1');
        $result    = new Result(['iri' => $iri, 'literal' => $literal, 'bnode' => $blankNode]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Binding 'literal' is not a IRI or Blank Node");
        $result->getResource('literal', $allowMissing);
    }

    public function testGetResourceInvalidMissing(): void
    {
        $iri       = new Iri('https://example.com/s');
        $literal   = new Literal('A label');
        $blankNode = new BlankNode('b1');
        $result    = new Result(['iri' => $iri, 'literal' => $literal, 'bnode' => $blankNode]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Binding 'missing' not found");
        $result->getResource('missing', false);
    }

    // ==================================================
    // getIri
    // ==================================================

    /** tests the getIri method */
    public function testGetIri(): void
    {
        $iri       = new Iri('https://example.com/s');
        $literal   = new Literal('A label');
        $blankNode = new BlankNode('b1');
        $result    = new Result(['iri' => $iri, 'literal' => $literal, 'bnode' => $blankNode]);

        self::assertSame($iri, $result->getIri('iri'));
        self::assertSame($iri, $result->getIri('iri', false));

        self::assertNull($result->getIri('missing'));
    }

    #[TestWith([true, false])]
    public function testGetIriInvalidLiteral(bool $allowMissing): void
    {
        $iri       = new Iri('https://example.com/s');
        $literal   = new Literal('A label');
        $blankNode = new BlankNode('b1');
        $result    = new Result(['iri' => $iri, 'literal' => $literal, 'bnode' => $blankNode]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Binding 'literal' is not a IRI");
        $result->getIri('literal', $allowMissing);
    }

    #[TestWith([true, false])]
    public function testGetIriInvalidBNode(bool $allowMissing): void
    {
        $iri       = new Iri('https://example.com/s');
        $literal   = new Literal('A label');
        $blankNode = new BlankNode('b1');
        $result    = new Result(['iri' => $iri, 'literal' => $literal, 'bnode' => $blankNode]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Binding 'bnode' is not a IRI");
        $result->getIri('bnode', $allowMissing);
    }

    public function testGetIriInvalidMissing(): void
    {
        $iri       = new Iri('https://example.com/s');
        $literal   = new Literal('A label');
        $blankNode = new BlankNode('b1');
        $result    = new Result(['iri' => $iri, 'literal' => $literal, 'bnode' => $blankNode]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Binding 'missing' not found");
        $result->getResource('missing', false);
    }

    // ==================================================
    // getLiteral
    // ==================================================

    /** tests the getLiteral method */
    public function testGetLiteral(): void
    {
        $iri       = new Iri('https://example.com/s');
        $literal   = new Literal('A label');
        $blankNode = new BlankNode('b1');
        $result    = new Result(['iri' => $iri, 'literal' => $literal, 'bnode' => $blankNode]);

        self::assertSame($literal, $result->getLiteral('literal'));
        self::assertSame($literal, $result->getLiteral('literal', false));

        self::assertNull($result->getLiteral('missing'));
    }

    #[TestWith([true, false])]
    public function testGetLiteralInvalidIri(bool $allowMissing): void
    {
        $iri       = new Iri('https://example.com/s');
        $literal   = new Literal('A label');
        $blankNode = new BlankNode('b1');
        $result    = new Result(['iri' => $iri, 'literal' => $literal, 'bnode' => $blankNode]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Binding 'iri' is not a Literal");
        $result->getLiteral('iri', $allowMissing);
    }

    #[TestWith([true, false])]
    public function testGetLiteralInvalidBNode(bool $allowMissing): void
    {
        $iri       = new Iri('https://example.com/s');
        $literal   = new Literal('A label');
        $blankNode = new BlankNode('b1');
        $result    = new Result(['iri' => $iri, 'literal' => $literal, 'bnode' => $blankNode]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Binding 'bnode' is not a Literal");
        $result->getLiteral('bnode', $allowMissing);
    }

    public function testGetLiteralInvalidMissing(): void
    {
        $iri       = new Iri('https://example.com/s');
        $literal   = new Literal('A label');
        $blankNode = new BlankNode('b1');
        $result    = new Result(['iri' => $iri, 'literal' => $literal, 'bnode' => $blankNode]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Binding 'missing' not found");
        $result->getLiteral('missing', false);
    }

    // ==================================================
    // getBlankNode
    // ==================================================

    /** tests the getBlankNode method */
    public function testGetBlankNode(): void
    {
        $iri       = new Iri('https://example.com/s');
        $literal   = new Literal('A label');
        $blankNode = new BlankNode('b1');
        $result    = new Result(['iri' => $iri, 'literal' => $literal, 'bnode' => $blankNode]);

        self::assertSame($blankNode, $result->getBlankNode('bnode'));
        self::assertSame($blankNode, $result->getBlankNode('bnode', false));

        self::assertNull($result->getBlankNode('missing'));
    }

    #[TestWith([true, false])]
    public function testGetBlankNodeInvalidIri(bool $allowMissing): void
    {
        $iri       = new Iri('https://example.com/s');
        $literal   = new Literal('A label');
        $blankNode = new BlankNode('b1');
        $result    = new Result(['iri' => $iri, 'literal' => $literal, 'bnode' => $blankNode]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Binding 'iri' is not a Blank Node");
        $result->getBlankNode('iri', $allowMissing);
    }

    #[TestWith([true, false])]
    public function testGetBlankNodeInvalidLiteral(bool $allowMissing): void
    {
        $iri       = new Iri('https://example.com/s');
        $literal   = new Literal('A label');
        $blankNode = new BlankNode('b1');
        $result    = new Result(['iri' => $iri, 'literal' => $literal, 'bnode' => $blankNode]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Binding 'literal' is not a Blank Node");
        $result->getBlankNode('literal', $allowMissing);
    }

    public function testGetBlankNodeInvalidMissing(): void
    {
        $iri       = new Iri('https://example.com/s');
        $literal   = new Literal('A label');
        $blankNode = new BlankNode('b1');
        $result    = new Result(['iri' => $iri, 'literal' => $literal, 'bnode' => $blankNode]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Binding 'missing' not found");
        $result->getBlankNode('missing', false);
    }
}
