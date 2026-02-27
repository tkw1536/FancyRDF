<?php

declare(strict_types=1);

namespace FancyRDF\Dataset\RdfCanon;

use RuntimeException;

/**
 * Thrown when canonicalization is aborted due to configured safety limits.
 */
final class CanonicalizationLimitExceeded extends RuntimeException
{
    public function __construct(
        public readonly string $limit,
        public readonly string $context,
        string $message,
    ) {
        parent::__construct($message);
    }
}
