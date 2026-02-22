<?php

declare(strict_types=1);

namespace FancyRDF\Term;

use DOMDocument;
use DOMElement;
use DOMNode;
use FancyRDF\Xml\XMLSerializable;
use InvalidArgumentException;
use JsonSerializable;
use Override;

/**
 * Represents a RDF 1.1 Term.
 *
 * A term is always one of the following three subclasses:
 *
 * - A Literal
 * - An IRI
 * - A Blank Node
 *
 * @internal
 *
 * @see https://www.w3.org/TR/rdf11-concepts/#dfn-rdf-term
 * @see \FancyRDF\Term\Literal
 * @see \FancyRDF\Term\Iri
 * @see \FancyRDF\Term\BlankNode
 *
 * This class should not be used directly, instead only the subclasses should be used.
 *
 * @phpstan-import-type IRIArray from Iri
 * @phpstan-import-type LiteralArray from Literal
 * @phpstan-import-type BlankNodeArray from BlankNode
 */
abstract class Term implements JsonSerializable, XMLSerializable
{
    /**
     * Encodes this term as a JSON object.
     *
     * The encoding complies with section 2.3.1 of the SPARQL 1.1 Query Results JSON Format.
     *
     * @see https://www.w3.org/TR/sparql11-results-json/#select-encode-terms
     *
     * @return IRIArray|LiteralArray|BlankNodeArray
     */
    #[Override]
    abstract public function jsonSerialize(): array;

    /**
     * Encodes this term as an XML Element.
     *
     * The encoding complies with section 2.3.1 of the SPARQL 1.1 Query Results JSON Format.
     *
     * @see https://www.w3.org/TR/sparql11-results-xml/#vb-results
     */
    #[Override]
    abstract public function xmlSerialize(DOMDocument $document): DOMNode;

    /**
     * Decodes a JSON object into the RDF term it represents.
     *
     * @see https://www.w3.org/TR/sparql11-results-json/#select-decode-terms
     *
     * @param mixed[] $data
     *   A JSON object represented as an RDF term per section 3.2.2 of the SPARQL 1.1 Query Results JSON Format.
     *   If the object does not represent a valid RDF term, the behavior is undefined.
     *
     * @throws InvalidArgumentException
     */
    public static function deserializeJSON(array $data): Iri|Literal|BlankNode
    {
        return match ($data['type'] ?? null) {
            'uri' => Iri::deserializeJSON($data),
            'bnode' => BlankNode::deserializeJSON($data),
            'literal' => Literal::deserializeJSON($data),
            default => throw new InvalidArgumentException('Invalid term type'),
        };
    }

    /**
     * Decodes an XML element into the RDF term it represents.
     *
     * @see https://www.w3.org/TR/sparql11-results-xml/#vb-results
     *
     * @param DOMElement $element
     *   An XML element representing an RDF term per section 2.3.1 of the SPARQL 1.1 Query Results XML Format.
     *   If the element does not represent a valid RDF term, the behavior is undefined.
     *
     * @throws InvalidArgumentException
     */
    public static function deserializeXML(DOMElement $element): Iri|Literal|BlankNode
    {
        return match ($element->localName) {
            'uri' => Iri::deserializeXML($element),
            'literal' => Literal::deserializeXML($element),
            'bnode' => BlankNode::deserializeXML($element),
            default => throw new InvalidArgumentException('Invalid element name'),
        };
    }

    /**
     * Checks if this term and the other term are equal.
     *
     * @see https://www.w3.org/TR/rdf11-concepts/#dfn-literal-term-equality
     *
     * @param bool $literal
     *   If set to true, check for RDF1.1 literal term-equality.
     *   Otherwise, attempt to check for value equality.
     *   Note that value equality only supports a specific subset of data types.
     */
    abstract public function equals(Iri|Literal|BlankNode $other, bool $literal = true): bool;

    /**
     * Compares this term with the other term using a lexiographical ordering.
     *
     * This function orders IRIs before Literals before Blank Nodes.
     * Within each type of term, the ordering is lexicographical.
     *
     * @param Iri|Literal|BlankNode $other
     *   The other term to compare.
     *
     * @return int
     *   a negative integer if $this is less than $other
     *   zero if $this is equal to $other
     *   a positive integer if $this is greater than $other
     */
    abstract public function compare(Iri|Literal|BlankNode $other): int;

    /**
     * Checks if this term can unify with the other term.
     *
     * Two terms are called unifiable under a mapping $partial if any of the following are true:
     * - The terms are literally term-equal.
     * - The terms are both blank nodes and the mapping $partial contains an entry for the other blank node.
     * - The terms are both resources and the mapping $partial contains an entry for the other resource.
     * - The terms are both literals and the mapping $partial contains an entry for the other literal.
     *
     * @param Iri|Literal|BlankNode $other
     *   The other term to check for unification.
     * @param array<string, string> &$partial
     *   The partial mapping of terms to be used for unification.
     *   Will be updated with the mapping if the terms are unifiable.
     * @param bool                  $literal
     *   If set to true, check for RDF1.1 literal term-equality.
     *   Otherwise, attempt to check for value equality.
     *   Note that value equality only supports a specific subset of data types.
     *
     * @return bool
     *   True if the terms are unifiable, false otherwise.
     */
    abstract public function unify(Iri|Literal|BlankNode $other, array &$partial, bool $literal = true): bool;

    /**
     * Checks if this term is grounded, meaning if it is not a blank node.
     */
    abstract public function isGrounded(): bool;
}
