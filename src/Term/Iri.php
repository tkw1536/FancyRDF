<?php

declare(strict_types=1);

namespace FancyRDF\Term;

use DOMDocument;
use DOMNode;
use FancyRDF\Uri\UriReference;
use FancyRDF\Xml\XMLUtils;
use InvalidArgumentException;
use Override;

use function is_string;
use function mb_ord;
use function mb_str_split;
use function preg_match;
use function sprintf;
use function strcmp;

/**
 * Represents an RDF1.1 IRI.
 *
 * @see https://www.w3.org/TR/rdf11-concepts/
 *
 * @phpstan-type IRIArray array{'type': 'uri', 'value': string}
 */
final class Iri extends Term
{
    /**
     * Constructs a IRI from an IRI reference string.
     *
     * @see https://www.rfc-editor.org/rfc/rfc3987
     * @see https://www.w3.org/TR/rdf11-concepts/#section-IRIs
     *
     * @param non-empty-string $iri
     *   A valid absolute IRI as per RFC3987.
     *   The IRI is not validated, and parts of the code simply assume it is a valid absolute IRI.
     *   If passed an invalid string, the behavior of the entire class is undefined.
     */
    public function __construct(readonly string $iri)
    {
    }

    /**
     * Splits this IRI into it's component parts as described in RFC 3986 and RFC 3987.
     *
     * @see https://www.rfc-editor.org/rfc/rfc3986
     * @see https://www.rfc-editor.org/rfc/rfc3987
     */
    public function toReference(): UriReference
    {
        return UriReference::parse($this->iri);
    }

    #[Override]
    public function equals(Term $other, bool $literal = true): bool
    {
        return $other instanceof Iri && $this->iri === $other->iri;
    }

    /** @param array<string, string> &$partial */
    #[Override]
    public function unify(Term $other, array &$partial, bool $literal = true): bool
    {
        return $other instanceof Iri && $this->iri === $other->iri;
    }

    /**
     * @param mixed[] $data
     *
     * @throws InvalidArgumentException
     */
    #[Override]
    public static function deserializeJSON(array $data): Iri
    {
        $type = $data['type'] ?? null;
        if ($type !== 'uri') {
            throw new InvalidArgumentException('Invalid resource type');
        }

        $value = $data['value'] ?? null;
        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException('IRI reference string must be a non-empty string');
        }

        return new Iri($value);
    }

    /** @throws InvalidArgumentException */
    #[Override]
    public static function deserializeXML(DOMNode $element): Iri
    {
        if ($element->localName !== 'uri') {
            throw new InvalidArgumentException('Invalid element name');
        }

        $literal = $element->textContent;
        if ($literal === '') {
            throw new InvalidArgumentException('Empty IRI');
        }

        return new Iri($literal);
    }

    #[Override]
    public function isGrounded(): bool
    {
        return true;
    }

    #[Override]
    public function compare(Iri|Literal|BlankNode $other): int
    {
        if (! ($other instanceof Iri)) {
            return -1;
        }

        return strcmp($this->iri, $other->iri);
    }

    /** @return IRIArray */
    #[Override]
    public function jsonSerialize(): array
    {
        return [
            'type' => 'uri',
            'value' => $this->iri,
        ];
    }

    #[Override]
    public function xmlSerialize(DOMDocument $document): DOMNode
    {
        return XMLUtils::createElement($document, 'uri', $this->iri);
    }

    #[Override]
    public function __toString(): string
    {
        // Fast path: If the IRI doesn't actually require any characters to be escaped
        // then we can return it as is and don't have to split and re-encode it.
        if (! preg_match('/[\x00-\x20<>"{}|^`\\\\]/u', $this->iri)) {
            return '<' . $this->iri . '>';
        }

        $result = '';
        $chars  = mb_str_split($this->iri, 1, 'UTF-8');
        foreach ($chars as $char) {
            $codePoint = mb_ord($char, 'UTF-8');
            if (
                $codePoint <= 0x20 ||
                $char === '<' ||
                $char === '>' ||
                $char === '"' ||
                $char === '{' ||
                $char === '}' ||
                $char === '|' ||
                $char === '^' ||
                $char === '`' ||
                $char === '\\'
            ) {
                $result .= self::uchar($codePoint);
            } else {
                $result .= $char;
            }
        }

        return '<' . $result . '>';
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
