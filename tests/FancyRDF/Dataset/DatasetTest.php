<?php

declare(strict_types=1);

namespace FancyRDF\Tests\FancyRDF\Dataset;

use FancyRDF\Dataset\Dataset;
use FancyRDF\Term\BlankNode;
use FancyRDF\Term\Iri;
use FancyRDF\Term\Literal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class DatasetTest extends TestCase
{
    /** @return iterable<string, array{Dataset, Dataset, array<string, string>, array<string, string>, bool, bool}> */
    public static function isIsomorphicToProvider(): iterable
    {
        $s = new Iri('https://example.org/s');
        $p = new Iri('https://example.org/p');
        $o = new Literal('o');
        $g = new Iri('https://example.org/g');
        $h = new Iri('https://example.org/h');

        $quad1 = [$s, $p, $o, $g];
        $quad2 = [$s, $p, $o, $h];

        $ds1 = new Dataset([$quad1]);
        $ds2 = new Dataset([$quad1]);
        $ds3 = new Dataset([$quad1, $quad2]);

        yield 'grounded equal' => [$ds1, $ds2, [], [], true, true];
        yield 'different size (A vs larger)' => [$ds1, $ds3, [], [], true, false];
        yield 'different size (B vs larger)' => [$ds2, $ds3, [], [], true, false];

        $p     = new Iri('https://example.org/p');
        $o     = new Literal('o');
        $g     = new Iri('https://example.org/g');
        $quad1 = [new BlankNode('b1'), $p, $o, $g];
        $quad2 = [new BlankNode('x'), $p, $o, $g];

        $ds1 = new Dataset([$quad1]);
        $ds2 = new Dataset([$quad2]);

        yield 'non-grounded isomorphic' => [$ds1, $ds2, [], ['b1' => 'x'], true, true];

        $g  = new Iri('https://example.org/g');
        $p1 = new Iri('https://example.org/p1');
        $o1 = new Literal('o1');
        $p2 = new Iri('https://example.org/p2');
        $o2 = new Literal('o2');
        $b1 = new BlankNode('b1');
        $b2 = new BlankNode('b2');
        $x  = new BlankNode('x');
        $y  = new BlankNode('y');

        $quad1         = [$b1, $p1, $o1, $g];
        $quadX         = [$x, $p1, $o1, $g];
        $quad2         = [$b2, $p2, $o2, $g];
        $quadY         = [$y, $p2, $o2, $g];
        $datasetAOrder = new Dataset([$quad2, $quad1]);
        $datasetBOrder = new Dataset([$quadX, $quadY]);
        $datasetCOrder = new Dataset([$quad1, $quad2]);
        $datasetDOrder = new Dataset([$quadX, $quadY]);

        yield 'unifiable different order (b2,b1 vs x,y)' => [$datasetAOrder, $datasetBOrder, [], ['b2' => 'y', 'b1' => 'x'], true, true];
        yield 'unifiable same order (b1,b2 vs x,y)' => [$datasetCOrder, $datasetDOrder, [], ['b1' => 'x', 'b2' => 'y'], true, true];
        yield 'unifiable self' => [$datasetAOrder, $datasetAOrder, [], ['b2' => 'b2', 'b1' => 'b1'], true, true];

        // a dataset with lots of repeated triples.
        $blah                 = new Iri('http://example.org/bar#blah');
        $datasetRepeats       = new Dataset([
            [$blah, $blah, $blah, null],
            [$blah, $blah, $blah, null],
        ]);
        $datasetDoesNotRepeat = new Dataset([
            [$blah, $blah, $blah, null],
        ]);

        yield 'same triple repeated' => [$datasetRepeats, $datasetDoesNotRepeat, [], [], true, true];

        $s = new Iri('https://example.org/s');
        $p = new Iri('https://example.org/p');
        $o = new Literal('o');
        $b = new BlankNode('b');

        $datasetNonEqualB = new Dataset([
            [$s, $p, $o, null],
            [$s, $p, $b, null],
        ]);
        $datasetNonEqualA = new Dataset([
            [$s, $p, $o, null],
            [$b, $p, $o, null],
        ]);

        yield 'non-equal different order' => [$datasetNonEqualB, $datasetNonEqualA, [], [], true, false];
    }

    /**
     * @param array<string, string> $inputPartial    Initial blank-node mapping passed into isIsomorphicTo.
     * @param array<string, string> $expectedPartial Expected mapping after the call (only asserted when $expectedEqual).
     */
    #[TestDox('$_dataname')]
    #[DataProvider('isIsomorphicToProvider')]
    public function testIsIsomorphicTo(Dataset $datasetA, Dataset $datasetB, array $inputPartial, array $expectedPartial, bool $literal, bool $expectedEqual): void
    {
        $partial = $inputPartial;
        $result  = $datasetA->isIsomorphicTo($datasetB, $partial, $literal);
        self::assertSame($expectedEqual, $result);

        if (! $expectedEqual) {
            return;
        }

        self::assertArraysAreIdenticalIgnoringOrder($expectedPartial, $partial);
    }
}
