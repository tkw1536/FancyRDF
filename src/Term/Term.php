<?php

declare(strict_types=1);

namespace FancySparql\Term;

use DOMDocument;
use DOMElement;
use DOMNode;
use FancySparql\Xml\XMLSerializable;
use InvalidArgumentException;
use JsonSerializable;
use Override;

/**
 * Represents a RDF 1.1 Term.
 *
 * @see https://www.w3.org/TR/rdf11-concepts/
 *
 * @phpstan-import-type ResourceElement from Resource
 * @phpstan-import-type LiteralElement from Literal
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
     * @return ResourceElement|LiteralElement
     */
    #[Override]
    abstract public function jsonSerialize(): array;

    /**
     * Encodes this term as an XML Element.
     *
     * The encoding complies with section 2.3.1 of the SPARQL 1.1 Query Results JSON Format.
     *
     * @see https://www.w3.org/TR/sparql11-results-xml/#vb-results
     *
     * @throws InvalidArgumentException
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
    public static function deserializeJSON(array $data): Resource|Literal
    {
        $type = $data['type'] ?? null;
        if ($type === 'uri' || $type === 'bnode') {
            return Resource::deserializeJSON($data);
        }

        if ($type === 'literal') {
            return Literal::deserializeJSON($data);
        }

        throw new InvalidArgumentException('Invalid term type');
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
    public static function deserializeXML(DOMElement $element): Resource|Literal
    {
        $type = $element->localName;
        if ($type === 'uri' || $type === 'bnode') {
            return Resource::deserializeXML($element);
        }

        if ($type === 'literal') {
            return Literal::deserializeXML($element);
        }

        throw new InvalidArgumentException('Invalid element name');
    }

    /**
     * Checks if this term and the other term are RDF1.1 literally term-equal.
     *
     * @see https://www.w3.org/TR/rdf11-concepts/#dfn-literal-term-equality
     */
    abstract public function equals(Term $other): bool;

    /**
     * Checks if this term can unify with the other term.
     *
     * Two terms are called unifiable under a mapping $partial if any of the following are true:
     * - The terms are literally term-equal.
     * - The terms are both blank nodes and the mapping $partial contains an entry for the other blank node.
     * - The terms are both resources and the mapping $partial contains an entry for the other resource.
     * - The terms are both literals and the mapping $partial contains an entry for the other literal.
     *
     * @param Term                  $other
     *   The other term to check for unification.
     * @param array<string, string> &$partial
     *   The partial mapping of terms to be used for unification.
     *   Will be updated with the mapping if the terms are unifiable.
     *
     * @return bool
     *   True if the terms are unifiable, false otherwise.
     */
    abstract public function unify(Term $other, array &$partial): bool;
}
