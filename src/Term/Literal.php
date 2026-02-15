<?php

declare(strict_types=1);

namespace FancySparql\Term;

use FancySparql\Xml\XMLUtils;
use InvalidArgumentException;
use Override;
use SimpleXMLElement;

use function is_string;

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
        if ($language !== null && ! is_string($language)) {
            throw new InvalidArgumentException('Language must be a string');
        }

        $datatype = $data['datatype'] ?? null;
        if ($datatype !== null && ! is_string($datatype)) {
            throw new InvalidArgumentException('Datatype must be a string');
        }

        return new Literal($value, $language, $datatype);
    }

    /** @throws InvalidArgumentException */
    #[Override]
    public static function deserializeXML(SimpleXMLElement $element): Literal
    {
        $elementName = $element->getName();
        if ($elementName !== 'literal') {
            throw new InvalidArgumentException('Invalid element name');
        }

        $language = $element->attributes('http://www.w3.org/XML/1998/namespace')['lang'] ?? $element['xml:lang'] ?? null;
        if ($language !== null) {
            $language = (string) $language;
        }

        $datatype = $element['datatype'];
        if ($datatype !== null) {
            $datatype = (string) $datatype;
        }

        return new Literal((string) $element, $language, $datatype);
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
