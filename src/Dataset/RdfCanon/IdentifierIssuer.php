<?php

declare(strict_types=1);

namespace FancyRDF\Dataset\RdfCanon;

/**
 * Identifier issuer as specified by RDF Dataset Canonicalization (RDFC-1.0) §4.5.
 *
 * @see https://www.w3.org/TR/rdf-canon/#issue-identifier-algorithm
 */
final class IdentifierIssuer
{
    /** @var array<non-empty-string, non-empty-string> */
    private array $issuedIdentifiers = [];

    private int $counter = 0;

    /** @param non-empty-string $prefix */
    public function __construct(private readonly string $prefix)
    {
    }

    /**
     * Issues (or returns) the identifier for $existingIdentifier.
     *
     * @param non-empty-string $existingIdentifier
     *
     * @return non-empty-string
     */
    public function issue(string $existingIdentifier): string
    {
        if (isset($this->issuedIdentifiers[$existingIdentifier])) {
            return $this->issuedIdentifiers[$existingIdentifier];
        }

        $issued = $this->prefix . (string) $this->counter;

        $this->issuedIdentifiers[$existingIdentifier] = $issued;
        $this->counter++;

        return $issued;
    }

    /** @param non-empty-string $existingIdentifier */
    public function has(string $existingIdentifier): bool
    {
        return isset($this->issuedIdentifiers[$existingIdentifier]);
    }

    /** @param non-empty-string $existingIdentifier */
    public function get(string $existingIdentifier): string|null
    {
        return $this->issuedIdentifiers[$existingIdentifier] ?? null;
    }

    /**
     * Returns the issued identifiers map (in issuance order).
     *
     * @return array<non-empty-string, non-empty-string>
     */
    public function getIssuedIdentifiers(): array
    {
        return $this->issuedIdentifiers;
    }

    public function copy(): self
    {
        $copy                    = new self($this->prefix);
        $copy->issuedIdentifiers = $this->issuedIdentifiers;
        $copy->counter           = $this->counter;

        return $copy;
    }
}
