<?php

declare(strict_types=1);

namespace FancySparql\Term\Datatype;

use DOMDocument;
use DOMNode;
use Override;

/** @extends Datatype<DOMNode> */
final class XMLLiteral extends Datatype
{
    public const string IRI = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#XMLLiteral';

    /** @return list<string> */
    #[Override]
    public static function getIRIs(): array
    {
        return [self::IRI];
    }

    #[Override]
    public function toCanonicalForm(): string
    {
        return $this->toValue()->C14N(false, true) ?: $this->lexical;
    }

    #[Override]
    public function toValue(): DOMNode
    {
        $dom = new DOMDocument();

        // Ignoring the error is intended behavior.
        // This can happen if the XML is empty.
        @$dom->loadXML($this->lexical);

        return $dom;
    }
}
