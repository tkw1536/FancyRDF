<?php

declare(strict_types=1);

namespace FancySparql\Term\Datatype;

use DOMDocument;
use DOMNode;
use Override;
use RuntimeException;

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

    #[Override]
    public function toCanonicalForm(): string
    {
        $result = '';
        foreach ($this->toValue() as $node) {
            $norm = $node->C14N(false, true);
            if ($norm === false) {
                throw new RuntimeException('failed to canonicalize node');
            }

            $result .= $norm;
        }

        return $result;
    }

    /** @return list<DOMNode> */
    #[Override]
    public function toValue(): array
    {
        $dom = new DOMDocument();
        $dom->loadXML('<root>' . $this->lexical . '</root>');

        return iterator_to_array($dom->documentElement->childNodes ?? [], false);
    }
}
