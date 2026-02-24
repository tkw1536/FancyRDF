<?php

declare(strict_types=1);

namespace FancyRDF\Dataset;

use IteratorAggregate;
use Override;
use Traversable;

use function array_shift;
use function array_splice;
use function array_values;
use function assert;
use function count;
use function is_array;
use function iterator_to_array;
use function usort;

/**
 * Represents an RDF dataset.
 *
 * A dataset is a collection of quads.
 *
 * @phpstan-import-type TripleOrQuadArray from Quad
 * @implements IteratorAggregate<TripleOrQuadArray>
 */
final class Dataset implements IteratorAggregate
{
    /** @var list<TripleOrQuadArray> */
    private readonly array $quads;

    /**
     * Constructs a new dataset.
     *
     * @param iterable<TripleOrQuadArray> $quads
     *
     * @return void
     */
    public function __construct(
        mixed $quads = [],
    ) {
        $quads       = ! is_array($quads) ? iterator_to_array($quads) : $quads;
        $this->quads = array_values($quads);
    }

    /** @return Traversable<TripleOrQuadArray> */
    #[Override]
    public function getIterator(): Traversable
    {
        yield from $this->quads;
    }

    /**
     * Splits the quads into grounded and non-grounded quads.
     *
     * @return array<list<TripleOrQuadArray>, list<TripleOrQuadArray>>
     *   The first array contains the grounded quads, the second array contains the non-grounded quads.
     */
    private function splitQuads(bool $literal): array
    {
        $all = $this->quads;
        usort($all, [Quad::class, 'compare']);

        $unique = [];
        $prev   = null;
        foreach ($all as $quad) {
            if ($prev !== null && Quad::equals($prev, $quad, $literal)) {
                continue;
            }

            $unique[] = $quad;
            $prev     = $quad;
        }

        $all = $unique;

        $grounded    = [];
        $nonGrounded = [];

        foreach ($all as $quad) {
            if (Quad::isGrounded($quad)) {
                $grounded[] = $quad;
            } else {
                $nonGrounded[] = $quad;
            }
        }

        return [$grounded, $nonGrounded];
    }

    /**
     * Instantiates two new datasets and checks if they are isomorphic.
     *
     * @see Dataset::isIsomorphicTo()
     *
     * @param iterable<TripleOrQuadArray> $a
     * @param iterable<TripleOrQuadArray> $b
     * @param array<string, string>       &$partial
     *   Blank node mapping from this dataset's blank node IDs to the other's (updated when matching non-grounded quads).
     */
    public static function areIsomorphic(mixed $a, mixed $b, array &$partial, bool $literal = true): bool
    {
        return new Dataset($a)->isIsomorphicTo(new Dataset($b), $partial, $literal);
    }

    /**
     * Checks if this dataset is isomorphic to the other dataset.
     *
     * @param array<string, string> &$partial
     *   Blank node mapping from this dataset's blank node IDs to the other's (updated when matching non-grounded quads).
     */
    public function isIsomorphicTo(Dataset $other, array &$partial, bool $literal = true): bool
    {
        [$groundedThis, $nonGroundedThis]   = $this->splitQuads($literal);
        [$groundedOther, $nonGroundedOther] = $other->splitQuads($literal);

        if (count($groundedThis) !== count($groundedOther)) {
            return false;
        }

        if (count($nonGroundedThis) !== count($nonGroundedOther)) {
            return false;
        }

        // Compare grounded quads by lexicographical ordering (sort then compare).

        for ($i = 0; $i < count($groundedThis); $i++) {
            if (! Quad::equals($groundedThis[$i], $groundedOther[$i], $literal)) {
                return false;
            }
        }

        // Backtrack to find a blank-node mapping that makes non-grounded quads match.
        return $this->matchNonGroundedQuads($nonGroundedThis, $nonGroundedOther, $partial, $literal);
    }

    /**
     * Tries to find a permutation of $otherQuads that unifies with $thisQuads under a consistent $partial.
     *
     * @param list<TripleOrQuadArray> $ours
     * @param list<TripleOrQuadArray> $theirs
     * @param array<string, string>   &$partial
     */
    private function matchNonGroundedQuads(array $ours, array $theirs, array &$partial, bool $literal): bool
    {
        assert(count($ours) === count($theirs), 'by precondition');

        // no quads on either side, so we match for sure!
        if (count($ours) === 0) {
            return true;
        }

        // Pick a 'hero' quad to compare with remaining ones first.
        $hero = array_shift($ours);

        // Check each possible candidate if they can unify with the here.
        // For each check we need to reset the mapping back to the current one.
        // This is because it's passed by value and may make abitrary changes.
        $oldPartial = $partial;
        foreach ($theirs as $k => $candidate) {
            // reset to the old partial before trying the next candidate.
            $partial = $oldPartial;

            // the candidate must unify with our hero.
            if (! Quad::unify($hero, $candidate, $partial, $literal)) {
                continue;
            }

            // whatever is left over must unify.
            $newTheirs = $theirs;
            array_splice($newTheirs, $k, 1);
            if ($this->matchNonGroundedQuads($ours, $newTheirs, $partial, $literal)) {
                return true;
            }
        }

        // we didn't find a match for our hero.
        return false;
    }
}
