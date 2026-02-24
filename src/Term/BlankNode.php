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
use function strcmp;

/**
 * Represents an RDF1.1 Blank Node.
 *
 * @see https://www.w3.org/TR/rdf11-concepts/#dfn-blank-node
 *
 * @phpstan-type BlankNodeArray array{'type': 'bnode', 'value': string}
 */
final class BlankNode extends Term
{
    /**
     * Label of the blank node.
     *
     * The string '_:' followed by the identifier.
     *
     * @var non-empty-string
     */
    public readonly string $label;

    /**
     * Constructs a new blank node from it's identifier.
     *
     * @see https://www.w3.org/TR/rdf11-concepts/#section-blank-nodes
     *
     * @param non-empty-string $identifier
     *   A valid blank node identifier.
     */
    public function __construct(readonly string $identifier)
    {
        $this->label = '_:' . $identifier;
    }

    #[Override]
    public function equals(Term $other, bool $literal = true): bool
    {
        return $other instanceof BlankNode && $this->identifier === $other->identifier;
    }

    /** @param array<string, string> &$partial */
    #[Override]
    public function unify(Term $other, array &$partial, bool $literal = true): bool
    {
        if (! $other instanceof BlankNode) {
            return false;
        }

        // if there is one non-blank node, they can only unify if they have the same IRI
        $us   = $this->identifier;
        $them = $other->identifier;

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
    public static function deserializeJSON(array $data): BlankNode
    {
        $type = $data['type'] ?? null;
        if ($type !== 'bnode') {
            throw new InvalidArgumentException('Invalid resource type');
        }

        $value = $data['value'] ?? null;
        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException('Blank node identifier must be a non-empty string');
        }

        return new BlankNode($value);
    }

    /** @throws InvalidArgumentException */
    #[Override]
    public static function deserializeXML(DOMNode $element): BlankNode
    {
        if ($element->localName !== 'bnode') {
            throw new InvalidArgumentException('Invalid element name');
        }

        assert($element->textContent !== '', 'Blank node identifier must be a non-empty string');

        return new BlankNode($element->textContent);
    }

    #[Override]
    public function isGrounded(): bool
    {
        return false;
    }

    #[Override]
    public function compare(Iri|Literal|BlankNode $other): int
    {
        if (! ($other instanceof BlankNode)) {
            return 1;
        }

        return strcmp($this->identifier, $other->identifier);
    }

    /** @return BlankNodeArray */
    #[Override]
    public function jsonSerialize(): array
    {
        return [
            'type' => 'bnode',
            'value' => $this->identifier,
        ];
    }

    #[Override]
    public function xmlSerialize(DOMDocument $document): DOMNode
    {
        return XMLUtils::createElement($document, 'bnode', $this->identifier);
    }
}
