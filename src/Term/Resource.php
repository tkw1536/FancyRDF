<?php

declare(strict_types=1);

namespace FancySparql\Term;

use DOMDocument;
use DOMNode;
use FancySparql\Xml\XMLUtils;
use InvalidArgumentException;
use Override;

use function array_pop;
use function array_shift;
use function assert;
use function count;
use function explode;
use function implode;
use function in_array;
use function is_string;
use function preg_match;
use function str_starts_with;
use function strcmp;
use function strpos;
use function strrpos;
use function substr;

/**
 * Represents an RDF1.1 IRI or Blank Node.
 *
 * @see https://www.w3.org/TR/rdf11-concepts/
 *
 * @phpstan-type ResourceElement array{'type': 'uri', 'value': string} | array{'type': 'bnode', 'value': string}
 */
final class Resource extends Term
{
    /**
     * Constructs a new Resource from a URI or blank node ID.
     *
     * @see https://www.rfc-editor.org/rfc/rfc3987
     * @see https://www.w3.org/TR/rdf11-concepts/#section-IRIs
     * @see https://www.w3.org/TR/rdf11-concepts/#section-blank-nodes
     *
     * @param non-empty-string $iri
     *   A valid absolute IRI as per RFC3987 or a blank node identifier proceeded with '_:'.
     *   This class makes no attempt to validate either the IRI or blank node identifier.
     *   If passed an invalid string, the behavior of the entire class is undefined.
     */
    public function __construct(readonly string $iri)
    {
    }

    #[Override]
    public function equals(Term $other, bool $literal = true): bool
    {
        return $other instanceof Resource && $this->iri === $other->iri;
    }

    /** @param array<string, string> &$partial */
    #[Override]
    public function unify(Term $other, array &$partial, bool $literal = true): bool
    {
        if (! $other instanceof Resource) {
            return false;
        }

        // if there is one non-blank node, they can only unify if they have the same IRI
        $us   = $this->getBlankNodeId();
        $them = $other->getBlankNodeId();
        if ($us === null || $them === null) {
            return $this->iri === $other->iri;
        }

        if (isset($partial[$us])) {
            return $partial[$us] === $them;
        }

        // must be injective!
        if (in_array($them, $partial, true)) {
            return false;
        }

        // update the mapping!
        $partial[$us] = $them;

        return true;
    }

    /**
     * @param mixed[] $data
     *
     * @throws InvalidArgumentException
     */
    #[Override]
    public static function deserializeJSON(array $data): Resource
    {
        $type = $data['type'] ?? null;
        if ($type !== 'uri' && $type !== 'bnode') {
            throw new InvalidArgumentException('Invalid resource type');
        }

        $value = $data['value'] ?? null;
        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException('Resource value must be a non-empty string');
        }

        if ($type === 'bnode') {
            $value = '_:' . $value;
        }

        return new Resource($value);
    }

    /** @throws InvalidArgumentException */
    #[Override]
    public static function deserializeXML(DOMNode $element): Resource
    {
        $elementName = $element->localName;
        if ($elementName === 'uri') {
            $literal = $element->textContent;
            if ($literal === '') {
                throw new InvalidArgumentException('Empty URI');
            }

            return new Resource($literal);
        }

        if ($elementName === 'bnode') {
            return new Resource('_:' . $element->textContent);
        }

        throw new InvalidArgumentException('Invalid element name');
    }

    /**
     * @return string|null
     *  The blank node ID, or null if this is not a blank node.
     */
    public function getBlankNodeId(): string|null
    {
        if (! $this->isBlankNode()) {
            return null;
        }

        return substr($this->iri, 2);
    }

    /**
     * Checks if this is a blank node.
     */
    public function isBlankNode(): bool
    {
        return str_starts_with($this->iri, '_:');
    }

    #[Override]
    public function isGrounded(): bool
    {
        return ! $this->isBlankNode();
    }

    #[Override]
    public function compare(Term $other): int
    {
        if ($other instanceof Literal) {
            return $this->isBlankNode() ? 1 : -1;
        }

        assert($other instanceof Resource);

        if ($this->isBlankNode() !== $other->isBlankNode()) {
            return $this->isBlankNode() ? 1 : -1;
        }

        return strcmp($this->iri, $other->iri);
    }

    /** @return ResourceElement */
    #[Override]
    public function jsonSerialize(): array
    {
        $id = $this->getBlankNodeId();
        if ($id !== null) {
            return [
                'type' => 'bnode',
                'value' => $id,
            ];
        }

        return [
            'type' => 'uri',
            'value' => $this->iri,
        ];
    }

    #[Override]
    public function xmlSerialize(DOMDocument $document): DOMNode
    {
        $blankNodeID = $this->getBlankNodeId();

        return $blankNodeID !== null
            ? XMLUtils::createElement($document, 'bnode', $blankNodeID)
            : XMLUtils::createElement($document, 'uri', $this->iri);
    }

    /**
     * Joins a base URI with a relative URI according to RFC 3986.
     *
     * @param string $base     The base URI
     * @param string $relative The relative URI (may be empty, relative, or absolute)
     *
     * @return string The resolved absolute URI
     */
    public static function joinURLs(string $base, string $relative): string
    {
        // Handle empty relative URI - return base without fragment
        if ($relative === '') {
            $fragmentPos = strpos($base, '#');

            return $fragmentPos !== false ? substr($base, 0, $fragmentPos) : $base;
        }

        // Handle network path (starts with //)
        if (str_starts_with($relative, '//')) {
            $schemeEnd = strpos($base, '://');
            if ($schemeEnd !== false) {
                $scheme = substr($base, 0, $schemeEnd + 3);

                return $scheme . substr($relative, 2);
            }

            return $relative;
        }

        // Handle absolute URI (has scheme)
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $relative)) {
            return $relative;
        }

        // Handle fragment-only (starts with #)
        if (str_starts_with($relative, '#')) {
            $baseWithoutFragment = strpos($base, '#') !== false ? substr($base, 0, strpos($base, '#')) : $base;

            return $baseWithoutFragment . $relative;
        }

        // Remove fragment from base for resolution
        $baseWithoutFragment = strpos($base, '#') !== false ? substr($base, 0, strpos($base, '#')) : $base;

        // Handle absolute path (starts with /)
        if (str_starts_with($relative, '/')) {
            // Absolute path relative to base's authority
            $schemeEnd = strpos($baseWithoutFragment, '://');
            if ($schemeEnd !== false) {
                $authorityEnd = strpos($baseWithoutFragment, '/', $schemeEnd + 3);
                if ($authorityEnd !== false) {
                    return substr($baseWithoutFragment, 0, $authorityEnd) . $relative;
                }

                return $baseWithoutFragment . $relative;
            }

            return $relative;
        }

        // Relative path - resolve against base
        // Get base path (everything up to last /)
        $schemeEnd = strpos($baseWithoutFragment, '://');
        if ($schemeEnd === false) {
            // No scheme, treat as simple path
            $lastSlash = strrpos($baseWithoutFragment, '/');
            if ($lastSlash === false) {
                $baseDir = $baseWithoutFragment . '/';
            } else {
                $baseDir = substr($baseWithoutFragment, 0, $lastSlash + 1);
            }

            $combined = $baseDir . $relative;
            $parts    = explode('/', $combined);
        } else {
            // Has scheme - need to preserve scheme://authority
            $authorityStart = $schemeEnd + 3;
            $authorityEnd   = strpos($baseWithoutFragment, '/', $authorityStart);
            if ($authorityEnd === false) {
                // No path after authority
                $schemeAndAuthority = $baseWithoutFragment;
                $basePath           = '/';
            } else {
                $schemeAndAuthority = substr($baseWithoutFragment, 0, $authorityEnd);
                $basePath           = substr($baseWithoutFragment, $authorityEnd);
            }

            // Get directory part of base path
            $lastSlash = strrpos($basePath, '/');
            if ($lastSlash === false) {
                $baseDir = '/';
            } else {
                $baseDir = substr($basePath, 0, $lastSlash + 1);
            }

            $combined = $baseDir . $relative;
            $parts    = explode('/', $combined);
            // Remove empty first element (from leading /)
            if ($parts[0] === '') {
                array_shift($parts);
            }
        }

        // Normalize: handle ../
        $result = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                if (! empty($result) && $result[count($result) - 1] !== '..') {
                    array_pop($result);
                } elseif (empty($result)) {
                    // Can't go above root - ignore
                    continue;
                } else {
                    $result[] = $part;
                }
            } else {
                $result[] = $part;
            }
        }

        if ($schemeEnd !== false) {
            // Reconstruct URL with scheme://authority
            return $schemeAndAuthority . '/' . implode('/', $result);
        }

        return implode('/', $result);
    }
}
