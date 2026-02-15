<?php

declare(strict_types=1);

namespace FancySparql\Term;

use DOMElement;
use FancySparql\Xml\XMLSerializable;
use InvalidArgumentException;
use JsonSerializable;
use Override;

/**
 * Represents a SPARQL Term.
 *
 * @internal
 *
 * @phpstan-import-type ResourceElement from Resource
 * @phpstan-import-type LiteralElement from Literal
 */
abstract class Term implements JsonSerializable, XMLSerializable
{
    /**
     * Serializes the term to a JSON-encodable array.
     *
     * @return ResourceElement|LiteralElement
     */
    #[Override]
    abstract public function jsonSerialize(): array;

    /**
     * @param mixed[] $data
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

    abstract public function equals(Term $other): bool;
}
