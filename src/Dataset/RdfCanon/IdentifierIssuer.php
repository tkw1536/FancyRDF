<?php

declare(strict_types=1);

namespace FancyRDF\Dataset\RdfCanon;

use Generator;
use IteratorAggregate;
use Override;
use Traversable;

/**
 * An identifier issuer is used to issue new blank node identifiers.
 *
 * Implements the identifier issuer algorithm as specified by RDF Dataset Canonicalization (RDFC-1.0) 4.5.
 *
 * @see https://www.w3.org/TR/rdf-canon/#dfn-identifier-issuer
 * @see https://www.w3.org/TR/rdf-canon/#issue-identifier-algorithm
 *
 * @implements IteratorAggregate<non-empty-string, non-empty-string>
 */
final class IdentifierIssuer implements IteratorAggregate
{
    /**
     * An ordered map that relates blank node identifiers to issued identifiers.
     *
     * @see https://www.w3.org/TR/rdf-canon/#dfn-issued-identifiers-map
     *
     * @var array<non-empty-string, non-empty-string>
     */
    private array $issued = [];

    /*
     * A counter that is appended to the identifier prefix to create a blank node identifier.
     *
     * @see https://www.w3.org/TR/rdf-canon/#dfn-identifier-counter
     */
    private int $counter = 0;

    /**
     * Creates a new identifier issuer with the provided identifier prefix.
     *
     * @see https://www.w3.org/TR/rdf-canon/#dfn-identifier-prefix
     *
     * @param non-empty-string $prefix
     *   The identifier prefix is a string that is used at the beginning of a blank node identifier.
     */
    public function __construct(private readonly string $prefix)
    {
    }

    /**
     * Issues an identifier for the provided blank node identifier.
     *
     * Implements the identifier issuer algorithm as specified by RDF Dataset Canonicalization (RDFC-1.0) §4.5.
     *
     * @see https://www.w3.org/TR/rdf-canon/#issue-identifier-algorithm
     *
     * @param non-empty-string $identifier
     *   An existing blank node identifier to issue for.
     *
     * @return non-empty-string
     *   The issued identifier.
     */
    public function issue(string $identifier): string
    {
        // 1) If there is a map entry for existing identifier in issued identifiers map of I, return it.
        if (isset($this->issued[$identifier])) {
            return $this->issued[$identifier];
        }

        // 2) Generate issued identifier by concatenating identifier prefix with the string value of identifier counter.
        // 3) Add an entry mapping existing identifier to issued identifier to the issued identifiers map of I.
        // 4) Increment identifier counter.
        $issued                    = $this->prefix . (string) $this->counter;
        $this->issued[$identifier] = $issued;
        $this->counter++;

        // 5) Return the issued identifier.
        return $issued;
    }

    /**
     * Checks if there is a map entry for existing identifier in issued identifiers map.
     *
     * @param non-empty-string $identifier
     */
    public function has(string $identifier): bool
    {
        return isset($this->issued[$identifier]);
    }

    /**
     * Gets an issued identifier for the existing blank node identifier.
     *
     * @param non-empty-string $identifier
     *   The existing blank node identifier, or NULL if no entry exists.
     */
    public function get(string $identifier): string|null
    {
        return $this->issued[$identifier] ?? null;
    }

    /**
     * Gets an iterator over the issued identifiers.
     *
     * @return Generator<non-empty-string, non-empty-string>
     *   An iterator over the issued identifiers.
     */
    #[Override]
    public function getIterator(): Traversable
    {
        yield from $this->issued;
    }
}
