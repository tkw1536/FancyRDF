<?php

declare(strict_types=1);

namespace FancySparql\Term;

use DOMDocument;
use DOMElement;
use DOMNode;
use FancySparql\Xml\XMLUtils;
use InvalidArgumentException;
use Override;

use function is_string;

/**
 * Represents an RDF literal.
 *
 * @phpstan-type LiteralElement array{'type': 'literal', 'value': string, 'datatype'?: non-empty-string, 'xml:lang'?: non-empty-string}
 */
final class Literal extends Term
{
    /**
     * @param non-empty-string|null $language
     * @param non-empty-string|null $datatype
     */
    public function __construct(readonly string $value, readonly string|null $language = null, readonly string|null $datatype = null)
    {
        if ($language !== null && $datatype !== null) {
            throw new InvalidArgumentException('Literal cannot have both language and datatype');
        }
    }

    #[Override]
    public function equals(Term $other): bool
    {
        return $other instanceof Literal && $this->value === $other->value && $this->language === $other->language && $this->datatype === $other->datatype;
    }

    /**
     * @param mixed[] $data
     *
     * @throws InvalidArgumentException
     */
    #[Override]
    public static function deserializeJSON(array $data): Literal
    {
        $value = $data['value'] ?? null;
        if (! is_string($value)) {
            throw new InvalidArgumentException('Value must be a string');
        }

        $language = $data['language'] ?? null;
        if (! ($language === null || is_string($language)) || $language === '') {
            throw new InvalidArgumentException('Language must be a non-empty string');
        }

        $datatype = $data['datatype'] ?? null;
        if (! ($datatype === null || is_string($datatype)) || $datatype === '') {
            throw new InvalidArgumentException('Datatype must be a non-empty string');
        }

        return new Literal($value, $language, $datatype);
    }

    /** @throws InvalidArgumentException */
    #[Override]
    public static function deserializeXML(DOMElement $element): Literal
    {
        $elementName = $element->localName;
        if ($elementName !== 'literal') {
            throw new InvalidArgumentException('Invalid element name');
        }

        $language = $element->getAttributeNS('http://www.w3.org/XML/1998/namespace', 'lang');
        $language = $language !== '' ? $language : null;

        $datatype = $element->getAttribute('datatype');
        $datatype = $datatype !== '' ? $datatype : null;

        return new Literal($element->textContent, $language, $datatype);
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
    public function xmlSerialize(DOMDocument $document): DOMNode
    {
        $element = XMLUtils::createElement($document, 'literal', $this->value);

        $datatype = $this->datatype;
        if ($datatype) {
            $element->setAttribute('datatype', $datatype);
        }

        $lang = $this->language;
        if ($lang) {
            $element->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:lang', $lang);
        }

        return $element;
    }
}
