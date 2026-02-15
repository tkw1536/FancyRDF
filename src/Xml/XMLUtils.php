<?php

declare(strict_types=1);

namespace FancySparql\Xml;

use DOMDocument;
use RuntimeException;
use SimpleXMLElement;

use function htmlspecialchars;
use function strpos;
use function substr;

use const ENT_QUOTES;
use const ENT_XML1;

/**
 * A class that holds helper functions for XML.
 */
final class XMLUtils
{
    /** You cannot construct this class. */
    private function __construct()
    {
    }

    /**
     * Converts a SimpleXMLElement to a formatted XML string.
     *
     * @throws RuntimeException
     */
    public static function asFormattedXml(SimpleXMLElement $element): string
    {
        $dom = new DOMDocument('1.0');

        $dom->preserveWhiteSpace = false;
        $dom->formatOutput       = true;

        $xml = $element->asXML();
        if ($xml === false) {
            throw new RuntimeException('Failed to convert SimpleXMLElement to XML string');
        }

        $dom->loadXML($xml);

        $str = $dom->saveXML();
        if ($str === false) {
            throw new RuntimeException('Failed to convert DOMDocument to XML string');
        }

        return $str;
    }

    /**
     * Helper function to add a child element to an optional parent element.
     *
     * @throws RuntimeException
     */
    public static function addChild(
        SimpleXMLElement|null $parent,
        string $qualifiedName,
        string|null $value = null,
        string|null $namespace = null,
    ): SimpleXMLElement {
        if ($parent !== null) {
            $element = $parent->addChild($qualifiedName, $value, $namespace);
            if ($element === null) {
                throw new RuntimeException('Failed to add child element to parent');
            }

            return $element;
        }

        if ($namespace !== null) {
            $colonPos  = strpos($qualifiedName, ':');
            $escaped   = htmlspecialchars($namespace, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $xmlString = $colonPos !== false
                ? '<' . $qualifiedName . ' xmlns:' . substr($qualifiedName, 0, $colonPos) . '="' . $escaped . '" />'
                : '<' . $qualifiedName . ' xmlns="' . $escaped . '" />';
        } else {
            $xmlString = '<' . $qualifiedName . ' />';
        }

        $element = new SimpleXMLElement($xmlString);
        if ($value !== null) {
            $element[0] = $value;
        }

        return $element;
    }
}
