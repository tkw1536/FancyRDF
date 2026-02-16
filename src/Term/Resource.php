<?php

declare(strict_types=1);

namespace FancySparql\Term;

use DOMDocument;
use DOMNode;
use FancySparql\Xml\XMLUtils;
use InvalidArgumentException;
use Override;

use function is_string;
use function str_starts_with;
use function strlen;
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
     * @param string $iri
     *   A valid absolute IRI as per RFC3987 or a blank node identifier proceeded with '_:'.
     *   This class makes no attempt to validate either the IRI or blank node identifier.
     *   If passed an invalid string, the behavior of the entire class is undefined.
     */
    public function __construct(readonly string $iri)
    {
        if (str_starts_with($iri, '_:')) {
            if (strlen($iri) <= 2) {
                throw new InvalidArgumentException('Invalid blank node ID');
            }

            return;
        }
    }

    #[Override]
    public function equals(Term $other): bool
    {
        return $other instanceof Resource && $this->iri === $other->iri;
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
        if (! is_string($value)) {
            throw new InvalidArgumentException('Invalid resource value');
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
            return new Resource($element->textContent);
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
