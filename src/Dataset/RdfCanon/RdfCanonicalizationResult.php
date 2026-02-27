<?php

declare(strict_types=1);

namespace FancyRDF\Dataset\RdfCanon;

use FancyRDF\Dataset\Dataset;

use function implode;
use function usort;

/**
 * Result of RDF dataset canonicalization.
 */
final class RdfCanonicalizationResult
{
    /**
     * @param array<non-empty-string, non-empty-string> $blankNodeMap
     *   Map from input blank node identifiers to canonical identifiers (without "_:" prefix).
     */
    public function __construct(
        public readonly Dataset $dataset,
        public readonly array $blankNodeMap,
    ) {
    }

    /**
     * Serializes the canonicalized dataset to canonical N-Quads.
     *
     * @return string
     *   A canonical N-Quads document terminated by a final "\n" (unless the dataset is empty).
     */
    public function toCanonicalNQuads(): string
    {
        $lines = [];
        foreach ($this->dataset as $quad) {
            $lines[] = CanonicalNQuadsSerializer::serialize($quad, null, true);
        }

        usort($lines, 'strcmp');

        return implode('', $lines);
    }
}
