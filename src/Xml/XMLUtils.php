<?php

declare(strict_types=1);

namespace FancySparql\Xml;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;

use function assert;
use function htmlspecialchars;

use const ENT_QUOTES;
use const ENT_XML1;

/**
 * A class that holds helper functions for XML.
 */
final class XMLUtils
{
    public const string XML_DECLARATION = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    public const string XML_NAMESPACE   = 'http://www.w3.org/XML/1998/namespace';
    public const string XMLNS_NAMESPACE = 'http://www.w3.org/2000/xmlns/';

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

    /**
     * Given a completely valid XML string, returns the inner html of the node as a canonical string.
     */
    public static function serializerInnerXML(string $outerXml): string
    {
        $doc = new DOMDocument();
        if (! $doc->loadXML($outerXml)) {
            throw new RuntimeException('failed to read XML inside node');
        }

        $node = $doc->documentElement;
        if ($node === null) {
            throw new RuntimeException('failed to get document element');
        }

        $result = '';
        foreach ($node->childNodes ?? [] as $child) {
            $result .= self::serializeAddPrefixes($child, $node, ['xml' => true, 'xmlns' => true]);
        }

        return $result;
    }

    /**
     * Serializes a node within a given context, adding namespaces as far down the tree as possible.
     *
     * @param DOMNode             $node
     *   The node to serialize.
     * @param DOMNode|null        $context
     *   If set, a node that provides namespace context for serialization.
     *   If $node lives in the default namespace, all namespaces defined in $context will be added unless they are already added further down the tree.
     *   All namespaces defined in this node will be added, unless they have already been consumed somewhere down in the tree.
     * @param array<string, bool> $defined
     *   The namespaces that are already defined.
     * @param array<string, bool> &$consumed
     *   A set of namespace lookups that were "consumed" somewhere down in the tree.
     */
    private static function serializeAddPrefixes(DOMNode $node, DOMNode|null $context, array $defined, array &$consumed = []): string
    {
        // if it is not a DOM Element, we don't need to add any prefixes.
        if (! $node instanceof DOMElement) {
            $result = $node->C14N(false, true);
            if ($result === false) {
                throw new RuntimeException('failed to canonicalize node');
            }

            return $result;
        }

        $used                = [];
        $used[$node->prefix] = true;
        foreach ($node->attributes ?? [] as $attr) {
            if ($attr->prefix === '' && $attr->localName === 'xmlns') {
                $defined[''] = true;
                continue;
            }

            $used[$attr->prefix] = true;
        }

        // Next check which ones we need to define here and what they resolve to.
        // Note that the root namespace may not resolve to anything.
        $toDeclare = [];

        foreach ($used as $namespace => $value) {
            if (isset($defined[$namespace])) {
                continue;
            }

            $resolved = $namespace === '' ? $node->lookupNamespaceURI(null) : $node->lookupNamespaceURI($namespace);
            if ($resolved === null) {
                assert($namespace === '', 'everyting except the root namespace must be resolvable');
                continue;
            }

            $toDeclare[$namespace] = $resolved;
            $consumed[$namespace]  = true;
            $defined[$namespace]   = true;
        }

        // Now we can render the children and closing tag.
        $childrenAndClosing = '';
        foreach ($node->childNodes ?? [] as $child) {
            $childrenAndClosing .= self::serializeAddPrefixes($child, null, $defined, $consumed);
        }

        $childrenAndClosing .= '</' . $node->nodeName . '>';

        // If this node lives in the default namespace, we should include all the context attributes.
        // NOTE: Not entirely clear why this is the right behavior, but the official RDF/XML testcases need this to pass.
        if ($context !== null && ($node->namespaceURI === null || $node->namespaceURI === '')) {
            $contextNamespaces = self::getInScopeNamespaces($context);
            foreach ($contextNamespaces as $prefix => $uri) {
                if (isset($consumed[$prefix])) {
                    continue;
                }

                $toDeclare[$prefix] = $uri;
            }
        }

        // Mow we can finally render the opening tag.
        // This has to include the regular attributes and whatever we promised to declare.
        $openingTag = '<' . $node->nodeName;
        foreach ($node->attributes ?? [] as $attr) {
            $openingTag .= ' ' . $attr->nodeName . '="' . self::escapeXmlAttributeValue($attr->value) . '"';
        }

        foreach ($toDeclare as $prefix => $uri) {
            if ($prefix === '') {
                $openingTag .= ' xmlns="' . self::escapeXmlAttributeValue($uri) . '"';
                continue;
            }

            $openingTag .= ' xmlns:' . $prefix . '="' . self::escapeXmlAttributeValue($uri) . '"';
        }

        $openingTag .= '>';

        return $openingTag . $childrenAndClosing;
    }

    /** @return array<string, string> Namespaces of the root element as $prefix => $uri. */
    private static function getInScopeNamespaces(DOMNode $node): array
    {
        assert($node->ownerDocument !== null, 'node must have an owner document');
        $xpath = new DOMXPath($node->ownerDocument);

        $namespaceNodes = $xpath->query('namespace::*', $node);
        if ($namespaceNodes === false) {
            throw new RuntimeException('failed to get namespace nodes');
        }

        $namespaces = [];
        foreach ($namespaceNodes as $namespaceNode) {
            $prefix = $namespaceNode->localName;
            $uri    = $namespaceNode->nodeValue;

            // Skip the pre-defined xml and xmlns prefixes.
            // Also skip anything undefined.
            if ($prefix === 'xml' || $prefix === 'xmlns' || $prefix === null || $uri === null) {
                continue;
            }

            $namespaces[$prefix] = $uri;
        }

        return $namespaces;
    }

    private static function escapeXmlAttributeValue(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
