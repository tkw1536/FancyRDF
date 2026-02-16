<?php

declare(strict_types=1);

namespace FancySparql\Graph;

use FancySparql\Term\Literal;
use FancySparql\Term\Resource;
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
 * Serializes a triple or quad into an N-Triples or N-Quads file.
 *
 * The implementation guarantees that it serializes valid Sparql 1.1 triples and quads. If the underlying
 * Literal and Resources only contain valid data, it is guaranteed that the serialized string is also
 * standards-compliant.
 *
 * This is guaranteed by being able to serialize all literals from the RDF 1.1 test suite.
 *
 * This guarantee does not apply negatively: For an invalid term, the library may produce a valid string, may
 * produce an invalid term, or may throw an exception.
 *
 * @see https://www.w3.org/TR/n-triples/
 * @see https://www.w3.org/TR/n-quads/
 * @see https://www.w3.org/TR/rdf11-testcases/
 */
final class NFormatSerializer
{
    /** you cannot instantiate this class */
    private function __construct()
    {
    }

    /** @throws RuntimeException */
    public static function serialize(Resource|Literal $subject, Resource $predicate, Resource|Literal $object, Resource|null $graph = null): string
    {
        $out = self::serializeTerm($subject);

        $out .= ' ';
        $out .= self::serializeResource($predicate);

        $out .= ' ';
        $out .= self::serializeTerm($object);

        if ($graph !== null) {
            $out .= ' ';
            $out .= self::serializeResource($graph);
        }

        $out .= ' .';

        return $out;
    }

    public static function serializeTerm(Resource|Literal $term): string
    {
        if ($term instanceof Literal) {
            return self::serializeLiteral($term);
        }

        return self::serializeResource($term);
    }

  /**
   * Serializes a Resource as IRIREF or BLANK_NODE_LABEL per N-Triples grammar.
   */
    private static function serializeResource(Resource $resource): string
    {
        if ($resource->isBlankNode()) {
            return $resource->iri;
        }

        return '<' . self::escapeIri($resource->iri) . '>';
    }

  /**
   * Serializes a Literal as STRING_LITERAL_QUOTE with optional @lang or ^^IRI.
   */
    private static function serializeLiteral(Literal $term): string
    {
        $out = '"' . self::escapeLiteralString($term->lexical) . '"';
        if ($term->language !== null) {
            $out .= '@' . $term->language;
        } elseif ($term->datatype !== Literal::DATATYPE_STRING) {
            $out .= '^^<' . self::escapeIri($term->datatype) . '>';
        }

        return $out;
    }

  /**
   * Escapes a string for use inside IRIREF (between < and >).
   *
   * N-Triples IRIREF excludes #x00-#x20, <, >, ", {, }, |, ^, `, \.
   * Those are encoded as \uXXXX or \UXXXXXXXX per the grammar.
   */
    private static function escapeIri(string $uri): string
    {
        $result = '';
        $chars  = preg_split('//u', $uri, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            throw new RuntimeException(sprintf(
                'PCRE error in preg_split: %s (error code: %d)',
                preg_last_error_msg(),
                preg_last_error(),
            ));
        }

        foreach ($chars as $char) {
            if (strlen($char) !== 1) {
                $result .= $char;
                continue;
            }

            $ord = ord($char);
            if (
                $ord <= 0x20 || $char === '<' || $char === '>' || $char === '"' ||
                $char === '{' || $char === '}' || $char === '|' || $char === '^' ||
                $char === '`' || $char === '\\'
            ) {
                $result .= self::uchar($ord);
            } else {
                $result .= $char;
            }
        }

        return $result;
    }

  /**
   * Escapes a string for use inside STRING_LITERAL_QUOTE (between " and ").
   *
   * ECHAR for \ " ' \t \n \r \b \f; UCHAR for other control and non-ASCII.
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
            if (strlen($char) !== 1) {
                $ord     = mb_ord($char, 'UTF-8');
                $result .= self::uchar($ord);
                continue;
            }

            switch ($char) {
                case '\\':
                    $result .= '\\\\';
                    break;
                case '"':
                    $result .= '\\"';
                    break;
                case "\t":
                    $result .= '\\t';
                    break;
                case "\n":
                    $result .= '\\n';
                    break;
                case "\r":
                    $result .= '\\r';
                    break;
                case "\x08":
                    $result .= '\\b';
                    break;
                case "\f":
                    $result .= '\\f';
                    break;
                case "'":
                    $result .= "\\'";
                    break;
                default:
                    $ord = ord($char);
                    if ($ord < 0x20) {
                        $result .= self::uchar($ord);
                    } else {
                        $result .= $char;
                    }
            }
        }

        return $result;
    }

  /**
   * Formats a code point as N-Triples UCHAR (\uXXXX or \UXXXXXXXX).
   */
    private static function uchar(int $ord): string
    {
        if ($ord <= 0xFFFF) {
            return sprintf('\\u%04X', $ord);
        }

        return sprintf('\\U%08X', $ord);
    }
}
