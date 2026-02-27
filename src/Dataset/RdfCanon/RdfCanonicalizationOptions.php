<?php

declare(strict_types=1);

namespace FancyRDF\Dataset\RdfCanon;

use function strtolower;

/**
 * Configurable safety limits for RDF dataset canonicalization (RDFC-1.0).
 */
final class RdfCanonicalizationOptions
{
    /**
     * Hash algorithm used by RDFC-1.0 (default: sha256).
     *
     * @var non-empty-string
     */
    public readonly string $hashAlgorithm;

    /**
     * Creates a new RDFC-1.0 canonicalization options.
     *
     * If any limit is set to null, there is no limit.
     * The defaults for these are sufficient for small graphs
     * - in particular those in the test suite when run on a decent machine.
     *
     * @param non-empty-string $hashAlgorithm
     *   Hash algorithm used by RDFC-1.0 (default: sha256).
     * @param int<1, max>|null $maxPermutations
     *   Maximum number of permutations to explore in Hash N-Degree Quads.
     * @param int<1, max>|null $maxHashNDegreeDepth
     *   Maximum recursion depth for Hash N-Degree Quads.
     * @param int<1, max>|null $maxHashNDegreeQuadCalls
     *   Maximum number of calls to Hash N-Degree Quads.
     * @param int<0, max>|null $maxTimeMs
     *   Overall wall-clock budget in milliseconds.
     */
    public function __construct(
        string $hashAlgorithm = 'sha256',
        public readonly int|null $maxPermutations = 200_000,
        public readonly int|null $maxHashNDegreeDepth = 64,
        public readonly int|null $maxHashNDegreeQuadCalls = 1000,
        public readonly int|null $maxTimeMs = 2_000,
    ) {
        $this->hashAlgorithm = strtolower($hashAlgorithm);
    }
}
