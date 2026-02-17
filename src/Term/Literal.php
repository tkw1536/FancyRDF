<?php

declare(strict_types=1);

namespace FancySparql\Term;

use DOMDocument;
use DOMElement;
use DOMNode;
use FancySparql\Term\Datatype\Datatype;
use FancySparql\Term\Datatype\LangString;
use FancySparql\Term\Datatype\XSDString;
use FancySparql\Xml\XMLUtils;
use InvalidArgumentException;
use Override;

use function assert;
use function is_string;
use function strcmp;

/**
 * Represents an RDF1.1 Literal.
 *
 * @see https://www.w3.org/TR/rdf11-concepts/
 *
 * @phpstan-type LiteralElement array{'type': 'literal', 'value': string, 'datatype'?: non-empty-string, 'xml:lang'?: non-empty-string}
 */
final class Literal extends Term
{
    /**
     * Returns the RDF1.1 literal value of this literal as a php value.
     *
     * @see https://www.w3.org/TR/rdf11-concepts/#dfn-literal-value
     */
    public function getValue(): mixed
    {
        return $this->getDatatypeInstance()->toValue();
    }

    /** @var Datatype<mixed>|null */
    private Datatype|null $datatypeInstance = null;

    /**
     * Returns the RDF1.1 datatype of this literal.
     *
     * @return Datatype<mixed>
     */
    public function getDatatypeInstance(): Datatype
    {
        if ($this->datatypeInstance === null) {
            $this->datatypeInstance = Datatypes::getDatatype($this->datatype, $this->lexical, $this->language);
        }

        return $this->datatypeInstance;
    }

    /**
     * The RDF1.1 datatype IRI of this literal.
     *
     * @see https://www.w3.org/TR/rdf11-concepts/#dfn-datatype-iri
     *
     * @var non-empty-string
     */
    public readonly string $datatype;

    /**
     * Constructs a new Literal.
     *
     * @see https://www.w3.org/TR/rdf11-concepts/#section-Graph-Literal
     * @see https://www.w3.org/TR/rdf11-concepts/#section-IRIs
     * @see https://www.rfc-editor.org/info/bcp47
     *
     * @param string                $lexical
     *   The lexical form of the literal.
     *   The constructor makes no attempt to validate or parse the lexical form.
     * @param non-empty-string|null $datatype
     *   A datatype IRI as per RFC3987 that determins how the lexical form maps to the literal value.
     *   If omitted, the datatype IRI defaults to be either {@see self::DATATYPE_STRING} or {@see self::DATATYPE_LANG_STRING}, depending on if the language tag is set or not.
     *   The constructor makes no attempt to validate the IRI, or matching lexical form to the iri.
     *   If passed an invalid string in either of these fields, the behavior of the entire class is undefined.
     * @param non-empty-string|null $language
     *   A valid non-empty BCP47 language tag, if and only if the datatype iri is {@see self::DATATYPE_LANG_STRING} or NULL.
     *   The constructor makes no attempt to validate the language tag, and passing an invalid language tag may lead to undefined behavior.
     */
    public function __construct(public readonly string $lexical, public readonly string|null $language = null, string|null $datatype = null)
    {
        $this->datatype = $datatype ?? ($language === null ? XSDString::IRI : LangString::IRI);

        if (($this->datatype === LangString::IRI) !== ($language !== null)) {
            // Per the RDF 1.1. Standard: "if and only if the datatype IRI is [self::DATATYPE_LANG_STRING]
            // a non-empty language tag as defined by [BCP47]."
            throw new InvalidArgumentException('Literal must have a language tag if and only if the datatype IRI is ' . LangString::IRI);
        }
    }

    #[Override]
    public function equals(Term $other, bool $literal = true): bool
    {
        // first check for exact equality of language and datatype
        if (! $other instanceof Literal || $this->language !== $other->language || $this->datatype !== $other->datatype) {
            return false;
        }

        // if asking for literal equality, just compare the lexical forms.
        if ($literal) {
            return $this->lexical === $other->lexical;
        }

        // if not, compare their values
        return $this->getDatatypeInstance()->equals($other->getDatatypeInstance());
    }

    #[Override]
    public function compare(Term $other): int
    {
        if ($other instanceof Resource) {
            return $other->isBlankNode() ? -1 : 1;
        }

        assert($other instanceof Literal);

        $ourType   = $this->getTypeForCompare();
        $theirType = $other->getTypeForCompare();
        if ($ourType !== $theirType) {
            return $ourType - $theirType;
        }

        $ourLanguage   = $this->language ?? '';
        $theirLanguage = $other->language ?? '';
        if ($ourLanguage !== $theirLanguage) {
            return strcmp($ourLanguage, $theirLanguage);
        }

        $ourDatatype   = $this->datatype;
        $theirDatatype = $other->datatype;
        if ($ourDatatype !== $theirDatatype) {
            return strcmp($ourDatatype, $theirDatatype);
        }

        return strcmp($this->lexical, $other->lexical);
    }

    /** @param array<string, string> &$partial */
    #[Override]
    public function unify(Term $other, array &$partial, bool $literal = true): bool
    {
        return $this->equals($other, $literal);
    }

    #[Override]
    public function isGrounded(): bool
    {
        return true;
    }

    private function getTypeForCompare(): int
    {
        switch ($this->datatype) {
            case XSDString::IRI:
                return 0;

            case LangString::IRI:
                return 1;

            default:
                return 2;
        }
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

        $language = $element->getAttributeNS(XMLUtils::XML_NAMESPACE, 'lang');
        $language = $language !== '' ? $language : null;

        $datatype = $element->getAttribute('datatype');
        $datatype = $datatype !== '' ? $datatype : null;

        return new Literal($element->textContent, $language, $datatype);
    }

    /** @return LiteralElement */
    #[Override]
    public function jsonSerialize(): array
    {
        $data = ['type' => 'literal', 'value' => $this->lexical];
        if ($this->datatype === LangString::IRI) {
            assert($this->language !== null, 'Datatype indicates a language string');
            $data['language'] = $this->language;
        } elseif ($this->datatype !== XSDString::IRI) {
            $data['datatype'] = $this->datatype;
        }

        return $data;
    }

    #[Override]
    public function xmlSerialize(DOMDocument $document): DOMNode
    {
        $element = XMLUtils::createElement($document, 'literal', $this->lexical);

        if ($this->datatype === LangString::IRI) {
            assert($this->language !== null, 'Datatype indicates a language string');
            $element->setAttributeNS(XMLUtils::XML_NAMESPACE, 'xml:lang', $this->language);
        } elseif ($this->datatype !== XSDString::IRI) {
            $element->setAttribute('datatype', $this->datatype);
        }

        return $element;
    }
}
