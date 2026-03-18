<?php

declare(strict_types=1);

namespace FancyRDF\Formats;

use FancyRDF\Dataset\Quad;
use FancyRDF\Term\BlankNode;
use FancyRDF\Term\Iri;
use FancyRDF\Term\Literal;
use InvalidArgumentException;

use function assert;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Serializes triples and quads into Turtle or TriG without subject nesting.
 *
 * When constructed with $isTrig = false, this serializer emits Turtle and
 * rejects quads with a non-null graph component. When $isTrig = true, it
 * emits TriG and groups quads by graph name.
 *
 * @phpstan-import-type TripleOrQuadArray from Quad
 */
final class TrigSerializer
{
    /** @var array<string, non-empty-string> prefix => namespace URI */
    private array $prefixes;

    private string $buffer = '';

    private bool $started = false;

    private bool $closed = false;

    private Iri|BlankNode|null $currentGraph = null;

    private bool $hasWrittenSomething = false;

    /** @param array<string, non-empty-string> $prefixes */
    public function __construct(
        private readonly bool $isTrig = false,
        array $prefixes = [],
    ) {
        $this->prefixes = $prefixes;
    }

    /**
     * Writes a single triple or quad.
     *
     * @param TripleOrQuadArray $quad
     *
     * @throws InvalidArgumentException
     */
    public function writeQuad(array $quad): void
    {
        if ($this->closed) {
            throw new InvalidArgumentException('Cannot write after serializer has been closed');
        }

        $this->ensureStarted();

        [$subject, $predicate, $object, $graph] = $quad;

        if (! $this->isTrig && $graph !== null) {
            throw new InvalidArgumentException('Turtle mode cannot serialize quads; graph component must be null');
        }

        if (! $this->isTrig) {
            assert($graph === null);
            $this->buffer             .= $this->formatTriple($subject, $predicate, $object);
            $this->hasWrittenSomething = true;

            return;
        }

        $sameGraph = $this->isSameGraph($graph);

        if (! $sameGraph) {
            $previousGraph = $this->currentGraph;

            if ($previousGraph !== null) {
                $this->buffer             .= "}\n\n";
                $this->hasWrittenSomething = true;
            }

            $this->currentGraph = $graph;

            if ($graph !== null) {
                if ($previousGraph === null && $this->hasWrittenSomething) {
                    $this->buffer .= "\n";
                }

                $this->buffer             .= $this->formatTerm($graph) . " {\n";
                $this->hasWrittenSomething = true;
            }
        }

        if ($graph === null) {
            $this->buffer             .= $this->formatTriple($subject, $predicate, $object);
            $this->hasWrittenSomething = true;

            return;
        }

        $this->buffer             .= $this->formatTriple($subject, $predicate, $object);
        $this->hasWrittenSomething = true;
    }

    /**
     * Closes any open blocks and finishes the document.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        if (! $this->started) {
            $this->ensureStarted();
        }

        if (! $this->isTrig || $this->currentGraph === null) {
            return;
        }

        $this->buffer      .= "}\n";
        $this->currentGraph = null;
    }

    /**
     * Returns the serialized Turtle/TriG document.
     */
    public function getResult(): string
    {
        return $this->buffer;
    }

    // ====================
    // Internal helpers
    // ====================

    /** ensures that the serializer has been started */
    private function ensureStarted(): void
    {
        if ($this->started) {
            return;
        }

        $this->started = true;

        foreach ($this->prefixes as $prefix => $namespace) {
            $this->buffer .= '@prefix ' . $prefix . ': <' . $namespace . "> .\n";
        }

        if ($this->prefixes === []) {
            return;
        }

        $this->buffer .= "\n";
    }

    private function isSameGraph(Iri|BlankNode|null $graph): bool
    {
        if ($graph === null && $this->currentGraph === null) {
            return true;
        }

        if ($graph === null || $this->currentGraph === null) {
            return false;
        }

        return $this->currentGraph->equals($graph, true);
    }

    private function formatTriple(Iri|BlankNode $subject, Iri $predicate, Iri|Literal|BlankNode $object): string
    {
        return $this->formatTerm($subject)
            . ' '
            . $this->formatTerm($predicate)
            . ' '
            . $this->formatTerm($object)
            . " .\n";
    }

    private function formatTerm(Iri|Literal|BlankNode $term): string
    {
        if ($term instanceof Iri) {
            return $this->formatIri($term);
        }

        // Literals and blank nodes already have canonical string forms.
        return (string) $term;
    }

    /**
     * Formats an IRI using known prefixes when possible.
     */
    private function formatIri(Iri $iri): string
    {
        $value        = $iri->iri;
        $bestPrefix   = null;
        $bestNsLength = -1;

        foreach ($this->prefixes as $prefix => $namespace) {
            if (! str_starts_with($value, $namespace)) {
                continue;
            }

            $nsLength = strlen($namespace);
            if ($nsLength <= $bestNsLength) {
                continue;
            }

            $bestPrefix   = $prefix;
            $bestNsLength = $nsLength;
        }

        if ($bestPrefix === null) {
            // Fall back to full IRI in angle brackets.
            return '<' . $value . '>';
        }

        $local = substr($value, $bestNsLength);
        if ($local === '') {
            // Degenerate case; fall back to full IRI.
            return '<' . $value . '>';
        }

        return $bestPrefix . ':' . $local;
    }
}
