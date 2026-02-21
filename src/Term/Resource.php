<?php

declare(strict_types=1);

namespace FancyRDF\Term;

use DOMDocument;
use DOMNode;
use FancyRDF\Xml\XMLUtils;
use InvalidArgumentException;
use Override;

use function assert;
use function in_array;
use function is_string;
use function str_starts_with;
use function strcmp;
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
}
