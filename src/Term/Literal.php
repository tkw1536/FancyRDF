<?php

declare(strict_types=1);

namespace FancyRDF\Term;

use DOMDocument;
use DOMElement;
use DOMNode;
use FancyRDF\Exceptions\InvalidLexicalValueError;
use FancyRDF\Term\Datatype\Datatype;
use FancyRDF\Term\Datatype\LangString;
use FancyRDF\Term\Datatype\XSDString;
use FancyRDF\Xml\XMLUtils;
use InvalidArgumentException;
use Override;

use function assert;
use function is_string;
use function mb_ord;
use function mb_str_split;
use function mb_strlen;
use function ord;
use function preg_match;
use function sprintf;
use function strcmp;
use function strlen;
use function trigger_error;

use const E_USER_WARNING;

/**
 * Represents an RDF1.1 Literal.
 *
 * @see https://www.w3.org/TR/rdf11-concepts/#dfn-literal
 *
 * @phpstan-type LiteralArray array{'type': 'literal', 'value': string, 'datatype'?: non-empty-string, 'xml:lang'?: non-empty-string}
 */
final class Literal extends Term
{
    /**
     * The RDF1.1 datatype IRI of this literal.
     *
     * @see https://www.w3.org/TR/rdf11-concepts/#dfn-datatype-iri
     */
    public readonly Iri $datatype;

    /**
     * Constructs a new Literal.
     *
     * @see self::XSDString()
     * @see self::LangString()
     * @see self::Typed()
     * @see https://www.w3.org/TR/rdf11-concepts/#section-Graph-Literal
     * @see https://www.w3.org/TR/rdf11-concepts/#section-IRIs
     * @see https://www.rfc-editor.org/info/bcp47
     *
     * @param string                $lexical
     *   The lexical form of the literal.
     *   The constructor makes no attempt to validate or parse the lexical form.
     * @param Iri|null              $datatype
     *   A datatype IRI as per RFC3987 that determins how the lexical form maps to the literal value.
     *   If omitted, the datatype IRI defaults to be either {@see self::DATATYPE_STRING} or {@see self::DATATYPE_LANG_STRING}, depending on if the language tag is set or not.
     *   The constructor makes no attempt to validate the IRI, or matching lexical form to the iri.
     *   If passed an invalid string in either of these fields, the behavior of the entire class is undefined.
     * @param non-empty-string|null $language
     *   A valid non-empty BCP47 language tag, if and only if the datatype iri is {@see self::DATATYPE_LANG_STRING} or NULL.
     *   The constructor makes no attempt to validate the language tag, and passing an invalid language tag may lead to undefined behavior.
     *
     * @throws InvalidArgumentException if the language and datatype are in an invalid combination.
     */
    public function __construct(public readonly string $lexical, public readonly string|null $language = null, Iri|null $datatype = null)
    {
        $this->datatype = $datatype ?? ($language === null ? new Iri(XSDString::IRI) : new Iri(LangString::IRI));

        if (($this->datatype->iri === LangString::IRI) !== ($language !== null)) {
            // Per the RDF 1.1. Standard: "if and only if the datatype IRI is [self::DATATYPE_LANG_STRING]
            // a non-empty language tag as defined by [BCP47]."
            throw new InvalidArgumentException('Literal must have a language tag if and only if the datatype IRI is ' . LangString::IRI);
        }
    }

    /**
     * Creates a new XSDString literal.
     *
     * As opposed to the constructor, this method never throws an exception.
     *
     * @return Literal
     */
    public static function XSDString(string $lexical): self
    {
        try {
            $result = new self($lexical, null, null);
        } catch (InvalidArgumentException) {
            $result = null;
        }

        assert($result !== null, 'never reached: an XSDString literal is always valid');

        return $result;
    }

    /**
     * Creates a new LangString literal.
     *
     * As opposed to the constructor, this method never throws an exception.
     *
     * @param string           $lexical
     *   The lexical form of the literal.
     * @param non-empty-string $language
     *   A valid non-empty BCP47 language tag.
     *
     * @return Literal
     */
    public static function langString(string $lexical, string $language): self
    {
        try {
            $literal = new self($lexical, $language, null);
        } catch (InvalidArgumentException) {
            $literal = null;
        }

        assert($literal !== null, 'never reached: a LangString literal is always valid');

        return $literal;
    }

    /**
     * Creates a new literal with the specified datatype and lexical form.
     *
     * As opposed to the constructor, this method never throws an exception.
     *
     * @param string               $lexical
     *   The lexical form of the literal.
     * @param non-empty-string|Iri $datatype
     *   A datatype IRI as per RFC3987.
     *   If the datatype is LangString::IRI, a warning is issued and XSDString::IRI is used instead.
     *
     * @return Literal
     */
    public static function typed(string $lexical, string|Iri $datatype): self
    {
        if (
            (is_string($datatype) && $datatype === LangString::IRI) ||
            ($datatype instanceof Iri && $datatype->iri === LangString::IRI)
        ) {
            trigger_error('LangString literals cannot be created with a datatype IRI, using XSDString instead', E_USER_WARNING);

            return self::XSDString($lexical);
        }

        try {
            $literal = new self($lexical, null, is_string($datatype) ? new Iri($datatype) : $datatype);
        } catch (InvalidArgumentException) {
            $literal = null;
        }

        assert($literal !== null, 'never reached: a literal with non-LangString datatype is always valid');

        return $literal;
    }

    /**
     * Returns the RDF1.1 literal value of this literal as a php value.
     *
     * @see https://www.w3.org/TR/rdf11-concepts/#dfn-literal-value
     *
     * @throws InvalidLexicalValueError
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
            $this->datatypeInstance = Datatypes::getDatatype($this->datatype->iri, $this->lexical, $this->language);
        }

        return $this->datatypeInstance;
    }

    /**
     * Callers should use this method to check for special literals.
     *
     * @return string|Iri|null
     *   If this literal is of type LangString, returns the language tag as a string.
     *   If this literal does not have datatype XSDString, returns the datatype IRI.
     *   Otherwise, returns null.
     */
    public function getTypeOrLanguage(): string|Iri|null
    {
        if ($this->datatype->iri === LangString::IRI) {
            assert($this->language !== null, 'datatype indicates a language string');

            return $this->language;
        }

        assert($this->language === null, 'datatype indicates no language tag');
        if ($this->datatype->iri === XSDString::IRI) {
            return null;
        }

        return $this->datatype;
    }

    #[Override]
    public function equals(Iri|Literal|BlankNode $other, bool $literal = true): bool
    {
        // first check for exact equality of language and datatype
        if (! $other instanceof Literal || $this->language !== $other->language || ! $this->datatype->equals($other->datatype)) {
            return false;
        }

        // if asking for literal equality, just compare the lexical forms.
        if ($literal) {
            return $this->lexical === $other->lexical;
        }

        // if not, compare their values
        try {
            return $this->getDatatypeInstance()->equals($other->getDatatypeInstance());
        } catch (InvalidLexicalValueError) {
            return false;
        }
    }

    #[Override]
    public function compare(Iri|Literal|BlankNode $other): int
    {
        if ($other instanceof Iri) {
            return 1;
        }

        if ($other instanceof BlankNode) {
            return -1;
        }

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

        $ourDatatype   = $this->datatype->iri;
        $theirDatatype = $other->datatype->iri;
        if ($ourDatatype !== $theirDatatype) {
            return strcmp($ourDatatype, $theirDatatype);
        }

        return strcmp($this->lexical, $other->lexical);
    }

    /** @param array<string, string> &$partial */
    #[Override]
    public function unify(Iri|Literal|BlankNode $other, array &$partial, bool $literal = true): bool
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
        switch ($this->datatype->iri) {
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

        $datatype = $datatype !== null ? new Iri($datatype) : null;

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
        $datatype = $datatype !== '' ? new Iri($datatype) : null;

        return new Literal($element->textContent, $language, $datatype);
    }

    /** @return LiteralArray */
    #[Override]
    public function jsonSerialize(): array
    {
        $data           = ['type' => 'literal', 'value' => $this->lexical];
        $typeOrLanguage = $this->getTypeOrLanguage();
        if (is_string($typeOrLanguage)) {
            $data['language'] = $typeOrLanguage;
        } elseif ($typeOrLanguage instanceof Iri) {
            $data['datatype'] = $typeOrLanguage->iri;
        }

        return $data;
    }

    #[Override]
    public function xmlSerialize(DOMDocument $document): DOMNode
    {
        $element = XMLUtils::createElement($document, 'literal', $this->lexical);

        $typeOrLanguage = $this->getTypeOrLanguage();
        if (is_string($typeOrLanguage)) {
            $element->setAttributeNS(XMLUtils::XML_NAMESPACE, 'xml:lang', $typeOrLanguage);
        } elseif ($typeOrLanguage instanceof Iri) {
            $element->setAttribute('datatype', $typeOrLanguage->iri);
        }

        return $element;
    }

    /**
     * Serializes this Literal as STRING_LITERAL_QUOTE with optional @lang or ^^IRI.
     *
     * Per the canonical N-Quads specification:
     */
    #[Override]
    public function __toString(): string
    {
        $out = '"' . self::escapeLiteralString($this->lexical) . '"';
        if ($this->language !== null) {
            $out .= '@' . $this->language;
        } elseif ($this->datatype->iri !== XSDString::IRI) {
            // Per the canonical N-Quads specification:
            // Literals with the datatype http://www.w3.org/2001/XMLSchema#string MUST NOT use the datatype IRI part of the literal, and are represented using only STRING_LITERAL_QUOTE.
            $out .= '^^' . $this->datatype->__toString();
        }

        return $out;
    }

     /**
      * Escapes a string for use inside STRING_LITERAL_QUOTE.
      *
      * Per the canonical N-Quads specification:
      *   - Characters BS (backspace, code point U+0008), HT (horizontal tab, code point U+0009), LF (line feed, code point U+000A), FF (form feed, code point U+000C), CR (carriage return, code point U+000D), " (quotation mark, code point U+0022), and \ (backslash, code point U+005C) MUST be encoded using ECHAR.
      *   - Characters in the range from U+0000 to U+0007, VT (vertical tab, code point U+000B), characters in the range from U+000E to U+001F, DEL (delete, code point U+007F), and characters not matching the Char production from [XML11] MUST be represented by UCHAR using a lowercase \u with 4 HEXes.
      *   - All characters not required to be represented by ECHAR or UCHAR MUST be represented by their native [UNICODE] representation.
      */
    private static function escapeLiteralString(string $value): string
    {
        // Fast path: If we have only ascii characters and no special characters to escape
        // We can return the string as-is and don't need to do any escaping.
        if (
            strlen($value) === mb_strlen($value, 'UTF-8') &&
            preg_match('/[\x00-\x1F\x7F\\\\\x22]/', $value) !== 1
        ) {
            return $value;
        }

        $result = '';
        $chars  = mb_str_split($value, 1, 'UTF-8');
        foreach ($chars as $char) {
            if ($char === '\\') {
                $result .= '\\\\';
                continue;
            }

            if ($char === '"') {
                $result .= '\\"';
                continue;
            }

            if ($char === "\t") {
                $result .= '\\t';
                continue;
            }

            if ($char === "\n") {
                $result .= '\\n';
                continue;
            }

            if ($char === "\r") {
                $result .= '\\r';
                continue;
            }

            if ($char === "\x08") {
                $result .= '\\b';
                continue;
            }

            if ($char === "\f") {
                $result .= '\\f';
                continue;
            }

            $codePoint = strlen($char) === 1 ? ord($char) : mb_ord($char, 'UTF-8');
            if (
                $codePoint <= 0x1F ||
                $codePoint === 0x7F ||
                ! self::isXml11Char($codePoint)
            ) {
                $result .= self::uchar($codePoint);
                continue;
            }

            $result .= $char;
        }

        return $result;
    }

    private static function isXml11Char(int $codePoint): bool
    {
        return ($codePoint >= 0x1 && $codePoint <= 0xD7FF) ||
            ($codePoint >= 0xE000 && $codePoint <= 0xFFFD) ||
            ($codePoint >= 0x10000 && $codePoint <= 0x10FFFF);
    }

    /**
     * Escapes a character for use inside STRING_LITERAL_QUOTE.
     *
     * Per the canonical N-Quads specification:
     *
     *  - HEX MUST use only digits ([0-9]) and uppercase letters ([A-F]).
     */
    private static function uchar(int $codePoint): string
    {
        if ($codePoint <= 0xFFFF) {
            return sprintf('\\u%04X', $codePoint);
        }

        return sprintf('\\U%08X', $codePoint);
    }
}
