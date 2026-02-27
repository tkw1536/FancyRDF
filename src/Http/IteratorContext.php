<?php

declare(strict_types=1);

namespace FancyRDF\Http;

use Generator;

/**
 * A class used internally inside the IteratorStreamWrapper class.
 *
 * @internal
 */
final class IteratorContext
{
    /**
     * Constructs a new IteratorContext.
     *
     * @param callable(): Generator<int, string, mixed, void> $generator
     *   The generator to use to produce the data for the stream.
     * @param int|null                                        $size
     *   The total number of bytes expected to be read from the generator.
     *   If the total size is wrong, the behavior of the stream is undefined.
     */
    public function __construct(
        public readonly mixed $generator,
        public readonly int|null $size = null,
    ) {
    }
}
