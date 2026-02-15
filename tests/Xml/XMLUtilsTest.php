<?php

declare(strict_types=1);

namespace FancySparql\Tests\Xml;

use FancySparql\Xml\XMLUtils;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

final class XMLUtilsTest extends TestCase
{
    #[DataProvider('asFormattedXmlProvider')]
    public function testAsFormattedXml(string $inputXml, string $expected): void
    {
        $element = new SimpleXMLElement($inputXml);
        self::assertSame($expected, XMLUtils::asFormattedXml($element));
    }

    /** @return array<string, array{string, string}> */
    public static function asFormattedXmlProvider(): array
    {
        return [
            'empty element' => [
                '<root/>',
                "<?xml version=\"1.0\"?>\n<root/>\n",
            ],
            'element with text' => [
                '<root>hello</root>',
                "<?xml version=\"1.0\"?>\n<root>hello</root>\n",
            ],
            'element with child' => [
                '<root><child/></root>',
                "<?xml version=\"1.0\"?>\n<root>\n  <child/>\n</root>\n",
            ],
            'element with default namespace' => [
                '<root xmlns="http://example.com/"><child/></root>',
                "<?xml version=\"1.0\"?>\n<root xmlns=\"http://example.com/\">\n  <child/>\n</root>\n",
            ],
        ];
    }

    #[DataProvider('addChildProvider')]
    public function testAddChild(
        string $qualifiedName,
        string|null $value,
        string|null $namespace,
        string $wantXML,
    ): void {
        $parent = new SimpleXMLElement('<root/>');
        $child  = XMLUtils::addChild($parent, $qualifiedName, $value, $namespace);
        self::assertSame($wantXML, $child->asXML(), 'adding to existing parent works as expected');

        $standalone = XMLUtils::addChild(null, $qualifiedName, $value, $namespace);
        self::assertSame('<?xml version="1.0"?>' . "\n" . $wantXML . "\n", $standalone->asXML(), 'creating new element works as expected');
    }

    /** @return array<string, array{string, string|null, string|null, string}> */
    public static function addChildProvider(): array
    {
        return [
            'no value, no namespace'     => ['elem', null, null, '<elem/>'],

            'value, no namespace'        => ['elem', 'text', null, '<elem>text</elem>'],
            'value with namespace'       => ['elem', 'x', 'http://example.com/', '<elem xmlns="http://example.com/">x</elem>'],
            'empty value with namespace' => ['e', '', 'http://example.com/', '<e xmlns="http://example.com/"/>'],

            'namespaced element'        => ['x:elem', null, 'http://example.com/', '<x:elem xmlns:x="http://example.com/"/>'],
            'namespaced element with value' => ['x:elem', 'x', 'http://example.com/', '<x:elem xmlns:x="http://example.com/">x</x:elem>'],
            'namespaced element with empty value' => ['x:e', '', 'http://example.com/', '<x:e xmlns:x="http://example.com/"/>'],
        ];
    }
}
