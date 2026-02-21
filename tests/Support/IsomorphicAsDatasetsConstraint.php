<?php

declare(strict_types=1);

namespace FancyRDF\Tests\Support;

use FancyRDF\Dataset\Dataset;
use FancyRDF\Dataset\Quad;
use FancyRDF\Formats\NFormatSerializer;
use Override;
use PHPUnit\Framework\Constraint\Constraint;

use function array_is_list;
use function array_map;
use function implode;
use function is_array;
use function sort;

/** @phpstan-import-type TripleOrQuadArray from Quad */
final class IsomorphicAsDatasetsConstraint extends Constraint
{
    /** @param list<TripleOrQuadArray> $expected */
    public function __construct(private readonly array $expected, private readonly bool $literal = true)
    {
    }

    /** @phpstan-assert-if-true list<TripleOrQuadArray> $other */
    private static function isListOfQuads(mixed $other): bool
    {
        if (! is_array($other) || ! array_is_list($other)) {
            return false;
        }

        foreach ($other as $quad) {
            if (! Quad::isTripleOrQuadArray($quad)) {
                return false;
            }
        }

        return true;
    }

    #[Override]
    protected function matches(mixed $other): bool
    {
        if (! self::isListOfQuads($other)) {
            return false;
        }

        $expectedDataset = new Dataset($this->expected);
        $actualDataset   = new Dataset($other);

        $partial = [];

        return $expectedDataset->isIsomorphicTo($actualDataset, $partial, $this->literal);
    }

    #[Override]
    public function toString(): string
    {
        return 'two datasets are isomorphic ' . ($this->literal ? 'term-equal' : 'value-equal');
    }

    #[Override]
    protected function failureDescription(mixed $other): string
    {
        return 'two datasets are isomorphic ' . ($this->literal ? 'term-equal' : 'value-equal');
    }

    #[Override]
    protected function additionalFailureDescription(mixed $other): string
    {
        if (! self::isListOfQuads($other)) {
            return 'did not receive a list of quads';
        }

        $expectedNtriples = $this->renderTriples($this->expected);
        $actualNtriples   = $this->renderTriples($other);

        return "Expected:\n" . $expectedNtriples . "\n\Actual:\n" . $actualNtriples;
    }

    /**
     * Formats an array of triples as ntriples format (one per line, sorted).
     *
     * @param list<TripleOrQuadArray> $quads
     */
    private function renderTriples(array $quads): string
    {
        $ntriples = array_map(
            static fn (array $triple): string => NFormatSerializer::serialize($triple[0], $triple[1], $triple[2]),
            $quads,
        );

        sort($ntriples);

        return implode("\n", $ntriples);
    }
}
