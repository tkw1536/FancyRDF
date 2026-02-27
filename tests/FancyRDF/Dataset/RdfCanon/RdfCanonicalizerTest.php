<?php

declare(strict_types=1);

namespace FancyRDF\Tests\FancyRDF\Dataset\RdfCanon;

use FancyRDF\Dataset\Dataset;
use FancyRDF\Dataset\RdfCanon\CanonicalizationLimitExceeded;
use FancyRDF\Dataset\RdfCanon\RdfCanonicalizationOptions;
use FancyRDF\Dataset\RdfCanon\RdfCanonicalizer;
use FancyRDF\Term\BlankNode;
use FancyRDF\Term\Iri;
use FancyRDF\Term\Literal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

use function explode;
use function trim;

final class RdfCanonicalizerTest extends TestCase
{
    private static function iri(string $suffix): Iri
    {
        return new Iri('http://example.com/#' . $suffix);
    }

    /** @return iterable<string, array{Dataset, array<string, string>, list<string>}> */
    public static function canonicalizeProvider(): iterable
    {
        $p = self::iri('p');
        $q = self::iri('q');
        $r = self::iri('r');
        $s = self::iri('s');
        $t = self::iri('t');
        $u = self::iri('u');

        $e0 = new BlankNode('e0');
        $e1 = new BlankNode('e1');
        $e2 = new BlankNode('e2');
        $e3 = new BlankNode('e3');

        $p1 = self::iri('p1');
        $p2 = self::iri('p2');
        $p3 = self::iri('p3');

        $g0 = new BlankNode('g0');

        $foo = new Literal('Foo');

        // Example 2: https://www.w3.org/TR/rdf-canon/#ex-ca-unique-hashes
        yield 'unique first-degree hashes' => [
            new Dataset([
                [$p, $q, $e0, null],
                [$p, $r, $e1, null],
                [$e0, $s, $u, null],
                [$e1, $t, $u, null],
            ]),
            [
                'e0' => 'c14n0',
                'e1' => 'c14n1',
            ],
            [
                '_:c14n0 <http://example.com/#s> <http://example.com/#u> .',
                '_:c14n1 <http://example.com/#t> <http://example.com/#u> .',
                '<http://example.com/#p> <http://example.com/#q> _:c14n0 .',
                '<http://example.com/#p> <http://example.com/#r> _:c14n1 .',
            ],
        ];

        // Example 3: https://www.w3.org/TR/rdf-canon/#ex-ca-shared-hashes
        yield 'shared first-degree hashes' => [
            new Dataset([
                [$p, $q, $e0, null],
                [$p, $q, $e1, null],
                [$e0, $p, $e2, null],
                [$e1, $p, $e3, null],
                [$e2, $r, $e3, null],
            ]),
            [
                'e2' => 'c14n0',
                'e3' => 'c14n1',
                'e1' => 'c14n2',
                'e0' => 'c14n3',
            ],
            [
                '_:c14n0 <http://example.com/#r> _:c14n1 .',
                '_:c14n2 <http://example.com/#p> _:c14n1 .',
                '_:c14n3 <http://example.com/#p> _:c14n0 .',
                '<http://example.com/#p> <http://example.com/#q> _:c14n2 .',
                '<http://example.com/#p> <http://example.com/#q> _:c14n3 .',
            ],
        ];

        // Example 9: https://www.w3.org/TR/rdf-canon/#ex-duplicate-paths
        yield 'duplicate paths' => [
            new Dataset([
                [$e0, $p1, $e1, null],
                [$e1, $p2, $foo, null],
                [$e2, $p1, $e3, null],
                [$e3, $p2, $foo, null],
            ]),
            [
                'e0' => 'c14n0',
                'e1' => 'c14n1',
                'e2' => 'c14n2',
                'e3' => 'c14n3',
            ],
            [
                '_:c14n0 <http://example.com/#p1> _:c14n1 .',
                '_:c14n1 <http://example.com/#p2> "Foo" .',
                '_:c14n2 <http://example.com/#p1> _:c14n3 .',
                '_:c14n3 <http://example.com/#p2> "Foo" .',
            ],
        ];

        // Example 10: https://www.w3.org/TR/rdf-canon/#ex-dataset-bn-graph
        yield 'blank node graph name' => [
            new Dataset([
                [$e0, $p1, $e1, null],
                [$e1, $p2, new Literal('Foo'), null],
                [$e1, $p3, $g0, null],
                [$e0, $p1, $e1, $g0],
                [$e1, $p2, new Literal('Bar'), $g0],
            ]),
            [
                'e0' => 'c14n0',
                'e1' => 'c14n1',
                'g0' => 'c14n2',
            ],
            [
                '_:c14n0 <http://example.com/#p1> _:c14n1 _:c14n2 .',
                '_:c14n0 <http://example.com/#p1> _:c14n1 .',
                '_:c14n1 <http://example.com/#p2> "Bar" _:c14n2 .',
                '_:c14n1 <http://example.com/#p2> "Foo" .',
                '_:c14n1 <http://example.com/#p3> _:c14n2 .',
            ],
        ];
    }

    /**
     * @param array<string, string> $expectedBlankNodeMap
     * @param list<string>          $expectedNQuadsLines
     */
    #[DataProvider('canonicalizeProvider')]
    public function testCanonicalizeExamples(Dataset $dataset, array $expectedBlankNodeMap, array $expectedNQuadsLines): void
    {
        $canonicalizer = new RdfCanonicalizer();
        $result        = $canonicalizer->canonicalize($dataset);

        $lines = explode("\n", trim($result->toCanonicalNQuads()));

        self::assertArraysAreIdenticalIgnoringOrder($expectedBlankNodeMap, $result->blankNodeMap);
        self::assertArraysHaveIdenticalValuesIgnoringOrder($expectedNQuadsLines, $lines);
    }

    #[TestDox('aborts when permutation exploration limit is exceeded')]
    public function testAbortOnPermutationLimitExceeded(): void
    {
        $p = self::iri('p');

        $quads = [];
        $e0    = new BlankNode('e0');
        $e1    = new BlankNode('e1');

        for ($i = 0; $i < 4; $i++) {
            $quads[] = [$e0, $p, new BlankNode('a' . $i), null];
            $quads[] = [$e1, $p, new BlankNode('b' . $i), null];
        }

        $dataset = new Dataset($quads);

        $options = new RdfCanonicalizationOptions(maxPermutations: 10, maxTimeMs: 10_000);

        $canonicalizer = new RdfCanonicalizer($options);

        $this->expectException(CanonicalizationLimitExceeded::class);
        $canonicalizer->canonicalize($dataset);
    }
}
