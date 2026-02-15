<?php

declare(strict_types=1);

namespace FancySparql\Tests\Xml;

use DOMDocument;
use FancySparql\Xml\XMLUtils;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class XMLUtilsTest extends TestCase
{
    /** @return array<string, array{string, string|null}> */
    public static function parseAndGetRootNodeProvider(): array
    {
        return [
            'root only' => [XMLUtils::XML_DECLARATION . '<root/>' . "\n", null],
            'tag with content' => [XMLUtils::XML_DECLARATION . '<foo>bar</foo>' . "\n", null],
            'prefixed element' => [XMLUtils::XML_DECLARATION . '<ns:item xmlns:ns="http://example.com/ns"/>' . "\n", null],
            'invalid XML' => ['<unclosed>', 'Failed to parse XML'],
        ];
    }

    #[DataProvider('parseAndGetRootNodeProvider')]
    public function testParseAndGetRootNode(string $source, string|null $expectedExceptionMessage): void
    {
        if ($expectedExceptionMessage !== null) {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage($expectedExceptionMessage);
            @XMLUtils::parseAndGetRootNode($source);

            return;
        }

        $root = XMLUtils::parseAndGetRootNode($source);
        $doc  = $root->ownerDocument;
        self::assertNotNull($doc);
        $restringified = $doc->saveXML();
        self::assertNotFalse($restringified);
        self::assertSame($source, $restringified);
    }

    public function testFormatXMLWithSingleDocumentMultipleNodes(): void
    {
        $source = XMLUtils::XML_DECLARATION . '<root>  <a>text</a>  <b>  spaced  </b>  <c/></root>';
        $root   = XMLUtils::parseAndGetRootNode($source);
        $doc    = $root->ownerDocument;
        self::assertNotNull($doc);

        $nodeA = $doc->getElementsByTagName('a')->item(0);
        $nodeB = $doc->getElementsByTagName('b')->item(0);
        $nodeC = $doc->getElementsByTagName('c')->item(0);
        self::assertNotNull($nodeA);
        self::assertNotNull($nodeB);
        self::assertNotNull($nodeC);

        $formattedRootNoPrettify = XMLUtils::formatXML($root, false);
        self::assertSame('<root>  <a>text</a>  <b>  spaced  </b>  <c/></root>', $formattedRootNoPrettify);

        $formattedRootPrettify = XMLUtils::formatXML($root, true);
        self::assertSame('<root>  <a>text</a>  <b>  spaced  </b>  <c/></root>', $formattedRootPrettify);

        $formattedA = XMLUtils::formatXML($nodeA, false);
        self::assertSame('<a>text</a>', $formattedA);

        $formattedB = XMLUtils::formatXML($nodeB, false);
        self::assertSame('<b>  spaced  </b>', $formattedB);

        $formattedC = XMLUtils::formatXML($nodeC, false);
        self::assertSame('<c/>', $formattedC);
    }

    public function testFormatXMLPrettifyIndentsOutput(): void
    {
        $source    = '<root><a><b/></a></root>';
        $root      = XMLUtils::parseAndGetRootNode($source);
        $formatted = XMLUtils::formatXML($root, true);
        $expected  = "<root>\n  <a>\n    <b/>\n  </a>\n</root>";
        self::assertSame($expected, $formatted);
    }

    /** @return array<string, array{string, string|null, string|null, string}> */
    public static function createElementProvider(): array
    {
        return [
            'without namespace' => ['something', 'value', null, XMLUtils::XML_DECLARATION . "<something>value</something>\n"],
            'prefixed namespace' => ['ns:something', null, 'http://example.com/ns', XMLUtils::XML_DECLARATION . "<ns:something xmlns:ns=\"http://example.com/ns\"/>\n"],
            'default namespace' => ['something', 'content', 'http://example.com/default', XMLUtils::XML_DECLARATION . "<something xmlns=\"http://example.com/default\">content</something>\n"],
            'empty value' => ['empty', null, null, XMLUtils::XML_DECLARATION . "<empty/>\n"],
        ];
    }

    #[DataProvider('createElementProvider')]
    public function testCreateElement(string $qualifiedName, string|null $value, string|null $namespace, string $expected): void
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $el  = XMLUtils::createElement($doc, $qualifiedName, $value, $namespace);
        $doc->appendChild($el);

        $xml = $doc->saveXML();
        self::assertNotFalse($xml);
        self::assertSame($expected, $xml);
    }
}
