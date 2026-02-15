<?php

declare(strict_types=1);

namespace FancySparql\Term;

use FancySparql\Xml\XMLSerializable;
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
}
