<?php

declare(strict_types=1);

namespace FancySparql\Dataset;

use FancySparql\Term\Literal;
use FancySparql\Term\Resource;

/**
 * Functions acting on RDF triples and quads.
 *
 * @phpstan-type TripleArray array{Resource, Resource, Resource|Literal, null}
 * @phpstan-type QuadArray array{Resource, Resource, Resource|Literal, Resource}
 * @phpstan-type TripleOrQuadArray TripleArray|QuadArray
 */
final class Quad
{
    /** you cannot instantiate this class */
    private function __construct()
    {
    }

    /**
     * Returns the size of a quad or triple.
     *
     * @param TripleOrQuadArray $quad
     *
     * @return 3|4
     */
    public static function size(array $quad): int
    {
        return $quad[3] === null ? 3 : 4;
    }

    /**
     * Checks if the given quad is grounded.
     *
     * @param TripleOrQuadArray $quad
     */
    public static function isGrounded(array $quad): bool
    {
        return $quad[0]->isGrounded() && $quad[1]->isGrounded() && $quad[2]->isGrounded() && ($quad[3]?->isGrounded() ?? true);
    }

    /**
     * Compares two triples using lexiographical ordering.
     *
     * @param TripleOrQuadArray $left
     * @param TripleOrQuadArray $right
     *
     * @return int
     *   -1 if $left is less than $right
     *   0 if $left is equal to $right
     *   1 if $left is greater than $right
     */
    public static function compare(array $left, array $right): int
    {
        $leftSize  = self::size($left);
        $rightSize = self::size($right);
        if ($leftSize !== $rightSize) {
            return $leftSize - $rightSize;
        }

        $subject = $left[0]->compare($right[0]);
        if ($subject !== 0) {
            return $subject;
        }

        $predicate = $left[1]->compare($right[1]);
        if ($predicate !== 0) {
            return $predicate;
        }

        $object = $left[2]->compare($right[2]);
        if ($object !== 0) {
            return $object;
        }

        if ($left[3] === null || $right[3] === null) {
            return $right[3] === null ? 0 : -1;
        }

        return $left[3]->compare($right[3]);
    }

    /**
     * Checks if two triples or quads are term-equal (same size and each position equals).
     *
     * @param TripleOrQuadArray $left
     * @param TripleOrQuadArray $right
     */
    public static function equals(array $left, array $right): bool
    {
        if (self::size($left) !== self::size($right)) {
            return false;
        }

        if (! $left[0]->equals($right[0]) || ! $left[1]->equals($right[1]) || ! $left[2]->equals($right[2])) {
            return false;
        }

        if ($left[3] === null || $right[3] === null) {
            return $left[3] === null && $right[3] === null;
        }

        return $left[3]->equals($right[3]);
    }

    /**
     * Checks if the two triples or quads are unifiable.
     *
     * Two triples or quads are called unifiable under a mapping $partial if any of the following are true:
     * - The triples or quads are literally term-equal.
     * - The triples or quads are both triples and the mapping $partial contains an entry for the other triple.
     * - The triples or quads are both quads and the mapping $partial contains an entry for the other quad.
     *
     * @param TripleOrQuadArray     $left
     * @param TripleOrQuadArray     $right
     * @param array<string, string> &$partial
     *   The partial mapping of terms to be used for unification.
     */
    public static function unify(array $left, array $right, array &$partial): bool
    {
        // early exit: if one's a quad and the other's a triple, they cannot unify.
        if ($left[3] === null || $right[3] === null) {
            if ($left[3] !== null || $right[3] !== null) {
                return false;
            }
        }

        // unify subject, predicate, object
        if (! $left[0]->unify($right[0], $partial)) {
            return false;
        }

        if (! $left[1]->unify($right[1], $partial)) {
            return false;
        }

        if (! $left[2]->unify($right[2], $partial)) {
            return false;
        }

        // unify graph (iff it is set)
        if ($left[3] === null || $right[3] === null) {
            return true;
        }

        return $left[3]->unify($right[3], $partial);
    }
}
