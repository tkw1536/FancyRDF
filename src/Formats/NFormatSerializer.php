<?php

declare(strict_types=1);

namespace FancyRDF\Formats;

use FancyRDF\Dataset\Quad;
use FancyRDF\Term\BlankNode;
use FancyRDF\Term\Datatype\XSDString;
use FancyRDF\Term\Iri;
use FancyRDF\Term\Literal;
use RuntimeException;

use function mb_ord;
use function mb_str_split;
use function ord;
use function sprintf;
use function strlen;

/**
 * Serializes a triple or quad into canonical N-Triples or N-Quads format.
 *
 * The implementation guarantees that it serializes valid Sparql 1.1 triples and quads. If the underlying
 * Literal and Resources only contain valid data, it is guaranteed that the serialized string is also
 * standards-compliant.
 *
 * This is guaranteed by being able to serialize all literals from the RDF 1.1 test suite.
 *
 * This guarantee DOES NOT apply negatively: For an invalid term, the library may produce valid string, may
 * produce an invalid string, or may throw an exception.
 *
 * @see https://www.w3.org/TR/n-triples/
 * @see https://www.w3.org/TR/n-quads/
 * @see https://www.w3.org/TR/rdf11-testcases/
 * @see https://www.w3.org/TR/rdf-canon/#canonical-quads
 * @see https://www.w3.org/TR/n-triples/#canonical-ntriples
 *
 * @phpstan-import-type TripleOrQuadArray from Quad
 */
final class NFormatSerializer
{
    /** you cannot instantiate this class */
    private function __construct()
    {
    }

    /**
     * Serializes a triple or quad to a canonical N-Triples or N-Quads string.
     *
     * @see https://www.w3.org/TR/n-triples/#canonical-ntriples
     * @see https://www.w3.org/TR/rdf-canon/#canonical-quads
     *
     * @param TripleOrQuadArray $quad
     *   The quad (or triple) to serialize.
     * @param bool              $finalEOL
     *   Per the canonical N-Quads specification, the final EOL MUST be provided.
     *   Sometimes this is not desirable, e.g. when a later implode("\n") is used.
     *   This parameter can be set to FALSE to skip the final EOL.
     *
     * @return string
     *   The serialized quad.
     *
     * @throws RuntimeException
     */
    public static function serialize(array $quad, bool $finalEOL = true): string
    {
        [$subject, $predicate, $object, $graph] = $quad;

        $out = self::serializeTerm($subject);

        $out .= ' ';
        $out .= self::serializeIri($predicate);

        $out .= ' ';
        $out .= self::serializeTerm($object);

        if ($graph !== null) {
            $out .= ' ';
            $out .= self::serializeTerm($graph);
        }

        $out .= ' .';

        if ($finalEOL) {
            $out .= "\n";
        }

        return $out;
    }

    public static function serializeTerm(Iri|Literal|BlankNode $term): string
    {
        return match (true) {
            $term instanceof Literal => self::serializeLiteral($term),
            $term instanceof BlankNode => self::serializeBlankNode($term),
            $term instanceof Iri => self::serializeIri($term),
        };
    }

    private static function serializeIri(Iri $resource): string
    {
        return '<' . self::escapeIri($resource->iri) . '>';
    }

    private static function serializeBlankNode(BlankNode $blankNode): string
    {
        return '_:' . $blankNode->identifier;
    }

    /**
     * Serializes a Literal as STRING_LITERAL_QUOTE with optional @lang or ^^IRI.
     *
     * Per the canonical N-Quads specification:
     *
     *   - Literals with the datatype http://www.w3.org/2001/XMLSchema#string MUST NOT use the datatype IRI part of the literal, and are represented using only STRING_LITERAL_QUOTE.
     */
    private static function serializeLiteral(Literal $term): string
    {
        $out = '"' . self::escapeLiteralString($term->lexical) . '"';
        if ($term->language !== null) {
            $out .= '@' . $term->language;
        } elseif ($term->datatype !== XSDString::IRI) {
            $out .= '^^<' . self::escapeIri($term->datatype) . '>';
        }

        return $out;
    }

    /**
     * Escapes a string for use inside IRIREF (between < and >).
     *
     * N-Quads IRIREF excludes #x00-#x20, <, >, ", {, }, |, ^, `, \.
     * Those are encoded as \uXXXX or \UXXXXXXXX.
     */
    private static function escapeIri(string $iri): string
    {
        $result = '';
        $chars  = mb_str_split($iri, 1, 'UTF-8');
        foreach ($chars as $char) {
            $codePoint =  mb_ord($char, 'UTF-8');
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

        return $result;
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
