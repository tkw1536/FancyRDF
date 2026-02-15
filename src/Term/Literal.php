<?php

declare(strict_types=1);

namespace FancySparql\Term;

use FancySparql\Xml\XMLUtils;
use InvalidArgumentException;
use Override;
use SimpleXMLElement;

/**
 * Represents an RDF literal.
 *
 * @phpstan-type LiteralElement array{'type': 'literal', 'value': string, 'datatype'?: string, 'xml:lang'?: string}
 */
final class Literal extends Term
{
    public function __construct(readonly string $value, readonly string|null $language = null, readonly string|null $datatype = null)
    {
        if ($language !== null && $datatype !== null) {
            throw new InvalidArgumentException('Literal cannot have both language and datatype');
        }
    }

    /** @return LiteralElement */
    #[Override]
    public function jsonSerialize(): array
    {
        $data = ['type' => 'literal', 'value' => $this->value];
        if ($this->language !== null) {
            $data['language'] = $this->language;
        }

        if ($this->datatype !== null) {
            $data['datatype'] = $this->datatype;
        }

        return $data;
    }

    #[Override]
    public function xmlSerialize(SimpleXMLElement|null $parent = null): SimpleXMLElement
    {
        $element = XMLUtils::addChild($parent, 'literal', $this->value);

        $datatype = $this->datatype;
        if ($datatype) {
            $element->addAttribute('datatype', $datatype);
        }

        $lang = $this->language;
        if ($lang) {
            $element->addAttribute('xml:lang', $lang, 'http://www.w3.org/XML/1998/namespace');
        }

        return $element;
    }
}
