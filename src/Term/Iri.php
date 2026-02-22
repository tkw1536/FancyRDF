<?php

declare(strict_types=1);

namespace FancyRDF\Term;

use DOMDocument;
use DOMNode;
use FancyRDF\Xml\XMLUtils;
use InvalidArgumentException;
use Override;

use function is_string;
use function strcmp;

/**
 * Represents an RDF1.1 IRI.
 *
 * @see https://www.w3.org/TR/rdf11-concepts/
 *
 * @phpstan-type IRIArray array{'type': 'uri', 'value': string}
 */
final class Iri extends Term
{
    /**
     * Constructs a IRI from an IRI reference string.
     *
     * @see https://www.rfc-editor.org/rfc/rfc3987
     * @see https://www.w3.org/TR/rdf11-concepts/#section-IRIs
     *
     * @param non-empty-string $iri
     *   A valid absolute IRI as per RFC3987.
     *   The IRI is only validated if assertions are enabled, otherwise the code assumes it is a valid absolute IRI.
     *   If passed an invalid string, the behavior of the entire class is undefined.
     */
    public function __construct(readonly string $iri)
    {
    }

    #[Override]
    public function equals(Term $other, bool $literal = true): bool
    {
        return $other instanceof Iri && $this->iri === $other->iri;
    }

    /** @param array<string, string> &$partial */
    #[Override]
    public function unify(Term $other, array &$partial, bool $literal = true): bool
    {
        return $other instanceof Iri && $this->iri === $other->iri;
    }

    /**
     * @param mixed[] $data
     *
     * @throws InvalidArgumentException
     */
    #[Override]
    public static function deserializeJSON(array $data): Iri
    {
        $type = $data['type'] ?? null;
        if ($type !== 'uri') {
            throw new InvalidArgumentException('Invalid resource type');
        }

        $value = $data['value'] ?? null;
        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException('IRI reference string must be a non-empty string');
        }

        return new Iri($value);
    }

    /** @throws InvalidArgumentException */
    #[Override]
    public static function deserializeXML(DOMNode $element): Iri
    {
        if ($element->localName !== 'uri') {
            throw new InvalidArgumentException('Invalid element name');
        }

        $literal = $element->textContent;
        if ($literal === '') {
            throw new InvalidArgumentException('Empty IRI');
        }

        return new Iri($literal);
    }

    #[Override]
    public function isGrounded(): bool
    {
        return true;
    }

    #[Override]
    public function compare(Iri|Literal|BlankNode $other): int
    {
        if (! ($other instanceof Iri)) {
            return -1;
        }

        return strcmp($this->iri, $other->iri);
    }

    /** @return IRIArray */
    #[Override]
    public function jsonSerialize(): array
    {
        return [
            'type' => 'uri',
            'value' => $this->iri,
        ];
    }

    #[Override]
    public function xmlSerialize(DOMDocument $document): DOMNode
    {
        return XMLUtils::createElement($document, 'uri', $this->iri);
    }
}
