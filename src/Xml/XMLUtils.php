<?php

declare(strict_types=1);

namespace FancySparql\Xml;

use DOMDocument;
use DOMElement;
use DOMNode;
use RuntimeException;

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
     * Parses the given XML source and returns the root element.
     *
     * @throws RuntimeException
     */
    public static function parseAndGetRootNode(string $source): DOMElement
    {
        $dom     = new DOMDocument();
        $success = $dom->loadXML($source);
        if (! $success) {
            throw new RuntimeException('Failed to parse XML');
        }

        $element = $dom->documentElement;
        if ($element === null) {
            throw new RuntimeException('Failed to get root element');
        }

        return $element;
    }

    /**
     * Formats the given XML node and returns the result.
     *
     * @throws RuntimeException
     */
    public static function formatXML(DOMNode $node, bool $prettify = false): string
    {
        $doc = $node->ownerDocument;
        if ($doc === null) {
            throw new RuntimeException('Failed to get owner document');
        }

        $formatOutputBefore       = $doc->formatOutput;
        $preserveWhiteSpaceBefore = $doc->preserveWhiteSpace;

        $doc->preserveWhiteSpace = ! $prettify;
        $doc->formatOutput       = $prettify;

        try {
            $result = $doc->saveXML($node);
        } finally {
            $doc->formatOutput       = $formatOutputBefore;
            $doc->preserveWhiteSpace = $preserveWhiteSpaceBefore;
        }

        if ($result === false) {
            throw new RuntimeException('Failed to format XML');
        }

        return $result;
    }

    /**
     * Creates a new element inside the given document.
     *
     * @throws RuntimeException
     */
    public static function createElement(
        DOMDocument $document,
        string $qualifiedName,
        string|null $value = null,
        string|null $namespace = null,
    ): DOMElement {
        if ($namespace !== null) {
            $element = $document->createElementNS($namespace, $qualifiedName, $value ?? '');
        } else {
            $element = $document->createElement($qualifiedName, $value ?? '');
        }

        if ($element === false) {
            throw new RuntimeException('Failed to create element');
        }

        return $element;
    }
}
