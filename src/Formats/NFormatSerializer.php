<?php

declare(strict_types=1);

namespace FancyRDF\Formats;

use FancyRDF\Dataset\Quad;
use RuntimeException;

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

        $out = $subject->__toString();

        $out .= ' ';
        $out .= $predicate->__toString();

        $out .= ' ';
        $out .= $object->__toString();

        if ($graph !== null) {
            $out .= ' ';
            $out .= $graph->__toString();
        }

        $out .= ' .';

        if ($finalEOL) {
            $out .= "\n";
        }

        return $out;
    }
}
