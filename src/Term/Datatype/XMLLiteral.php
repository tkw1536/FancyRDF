<?php

declare(strict_types=1);

namespace FancyRDF\Term\Datatype;

use DOMDocument;
use DOMNode;
use FancyRDF\Exceptions\InvalidLexicalValueError;
use Override;

use function iterator_to_array;

/** @extends Datatype<list<DOMNode>> */
final class XMLLiteral extends Datatype
{
    public const string IRI = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#XMLLiteral';

    /** @return list<string> */
    #[Override]
    public static function getIRIs(): array
    {
        return [self::IRI];
    }

    /** @throws InvalidLexicalValueError */
    #[Override]
    public function toCanonicalForm(): string
    {
        $result = '';
        foreach ($this->toValue() as $node) {
            $norm = $node->C14N(false, true);
            if ($norm === false) {
                throw new InvalidLexicalValueError('failed to canonicalize node', $this->iri, $this->lexical, $this->language);
            }

            $result .= $norm;
        }

        return $result;
    }

    /**
     * @return list<DOMNode>
     *
     * @throws InvalidLexicalValueError
     */
    #[Override]
    public function toValue(): array
    {
        $dom = new DOMDocument();
        $ok  = @$dom->loadXML('<root>' . $this->lexical . '</root>');
        if (! $ok) {
            // TODO: We don't actually know if this is a valid XML document.
            // DOMDocument::loadXML() === false only tells us that it cannot be parsed.
            // So not sure which exception to throw here.
            throw new InvalidLexicalValueError('failed to parse XML', $this->iri, $this->lexical, $this->language);
        }

        return iterator_to_array($dom->documentElement->childNodes ?? [], false);
    }
}
