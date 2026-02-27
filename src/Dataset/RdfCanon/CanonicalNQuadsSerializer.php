<?php

declare(strict_types=1);

namespace FancyRDF\Dataset\RdfCanon;

use FancyRDF\Dataset\Quad;
use FancyRDF\Term\BlankNode;
use FancyRDF\Term\Datatype\XSDString;
use FancyRDF\Term\Iri;
use FancyRDF\Term\Literal;
use RuntimeException;

use function mb_ord;
use function ord;
use function preg_last_error;
use function preg_last_error_msg;
use function preg_split;
use function sprintf;
use function strlen;

use const PREG_SPLIT_NO_EMPTY;

/**
 * Canonical N-Quads serializer per RDF Dataset Canonicalization (RDFC-1.0) Appendix A.
 *
 * @see https://www.w3.org/TR/rdf-canon/#canonical-n-quads
 *
 * @phpstan-import-type TripleOrQuadArray from Quad
 */
final class CanonicalNQuadsSerializer
{
    private function __construct()
    {
    }

    /**
     * Serializes a quad/triple to canonical N-Quads, terminated by "\n" by default.
     *
     * @param TripleOrQuadArray                                 $quad
     *   The quad/triple to serialize.
     * @param callable(non-empty-string): non-empty-string|null $blankNodeIdentifierMapper
     *   Maps a blank node identifier to the identifier used in output (without the "_:" prefix).
     *   When omitted, blank node identifiers are used as-is.
     */
    public static function serialize(
        array $quad,
        callable|null $blankNodeIdentifierMapper = null,
        bool $withTrailingNewline = true,
    ): string {
        [$subject, $predicate, $object, $graph] = $quad;

        $out  = self::serializeTerm($subject, $blankNodeIdentifierMapper);
        $out .= ' ';
        $out .= self::serializeIri($predicate);
        $out .= ' ';
        $out .= self::serializeTerm($object, $blankNodeIdentifierMapper);

        if ($graph !== null) {
            $out .= ' ';
            $out .= self::serializeTerm($graph, $blankNodeIdentifierMapper);
        }

        $out .= ' .';

        if ($withTrailingNewline) {
            $out .= "\n";
        }

        return $out;
    }

        /** @param callable(non-empty-string): non-empty-string|null $blankNodeIdentifierMapper */
    public static function serializeTerm(Iri|Literal|BlankNode $term, callable|null $blankNodeIdentifierMapper = null): string
    {
        return match (true) {
            $term instanceof Iri => self::serializeIri($term),
            $term instanceof BlankNode => self::serializeBlankNode($term, $blankNodeIdentifierMapper),
            $term instanceof Literal => self::serializeLiteral($term),
        };
    }

    private static function serializeIri(Iri $resource): string
    {
        return '<' . self::escapeIri($resource->iri) . '>';
    }

    /** @param callable(non-empty-string): non-empty-string|null $blankNodeIdentifierMapper */
    private static function serializeBlankNode(BlankNode $blankNode, callable|null $blankNodeIdentifierMapper = null): string
    {
        $id = $blankNode->identifier;

        if ($blankNodeIdentifierMapper !== null) {
            $id = $blankNodeIdentifierMapper($id);
        }

        return '_:' . $id;
    }

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
        $chars  = preg_split('//u', $iri, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            throw new RuntimeException(sprintf(
                'PCRE error in preg_split: %s (error code: %d)',
                preg_last_error_msg(),
                preg_last_error(),
            ));
        }

        foreach ($chars as $char) {
            $codePoint = strlen($char) === 1 ? ord($char) : mb_ord($char, 'UTF-8');
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
     * Escapes a string for use inside STRING_LITERAL_QUOTE (between " and ").
     *
     * Canonical N-Quads requires that only characters that MUST be escaped are escaped.
     * All others MUST be represented in native Unicode form.
     */
    private static function escapeLiteralString(string $value): string
    {
        $result = '';
        $chars  = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            throw new RuntimeException(sprintf(
                'PCRE error in preg_split: %s (error code: %d)',
                preg_last_error_msg(),
                preg_last_error(),
            ));
        }

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

    private static function uchar(int $codePoint): string
    {
        if ($codePoint <= 0xFFFF) {
            return sprintf('\\u%04X', $codePoint);
        }

        return sprintf('\\U%08X', $codePoint);
    }
}
