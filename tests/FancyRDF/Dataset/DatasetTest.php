<?php

declare(strict_types=1);

namespace FancyRDF\Tests\FancyRDF\Dataset;

use FancyRDF\Dataset\Dataset;
use FancyRDF\Term\BlankNode;
use FancyRDF\Term\Iri;
use FancyRDF\Term\Literal;
use PHPUnit\Framework\TestCase;

final class DatasetTest extends TestCase
{
    public function testIsIsomorphicToGrounded(): void
    {
        $s    = new Iri('https://example.org/s');
        $p    = new Iri('https://example.org/p');
        $o    = new Literal('o');
        $g    = new Iri('https://example.org/g');
        $quad = [$s, $p, $o, $g];

        $equalA        = new Dataset([$quad]);
        $equalB        = new Dataset([$quad]);
        $differentSize = new Dataset([$quad, $quad]);

        $partial = [];
        self::assertTrue($equalA->isIsomorphicTo($equalB, $partial));
        self::assertSame([], $partial);

        $partial = [];
        self::assertFalse($equalA->isIsomorphicTo($differentSize, $partial));
        $partial = [];
        self::assertFalse($equalB->isIsomorphicTo($differentSize, $partial));
    }

    public function testIsIsomorphicToNonGrounded(): void
    {
        $p = new Iri('https://example.org/p');
        $o = new Literal('o');
        $g = new Iri('https://example.org/g');

        $blankA = new BlankNode('b1');
        $quadA  = [$blankA, $p, $o, $g];

        $blankB = new BlankNode('x');
        $quadB  = [$blankB, $p, $o, $g];

        $datasetA = new Dataset([$quadA]);
        $datasetB = new Dataset([$quadB]);

        $partial = [];
        self::assertTrue($datasetA->isIsomorphicTo($datasetB, $partial));
        self::assertSame(['b1' => 'x'], $partial);
    }

    public function testIsIsomorphicToUnifiableQuadsDifferentOrder(): void
    {
        $g = new Iri('https://example.org/g');

        $p1 = new Iri('https://example.org/p1');
        $o1 = new Literal('o1');
        $p2 = new Iri('https://example.org/p2');
        $o2 = new Literal('o2');

        $blankB1 = new BlankNode('b1');
        $blankB2 = new BlankNode('b2');

        $blankX = new BlankNode('x');
        $blankY = new BlankNode('y');

        $quad1 = [$blankB1, $p1, $o1, $g];
        $quadX = [$blankX, $p1, $o1, $g];

        $quad2 = [$blankB2, $p2, $o2, $g];
        $quadY = [$blankY, $p2, $o2, $g];

        $datasetA = new Dataset([$quad2, $quad1]);
        $datasetB = new Dataset([$quadX, $quadY]);

        $datasetC = new Dataset([$quad1, $quad2]);
        $datasetD = new Dataset([$quadX, $quadY]);

        $partial = [];
        self::assertTrue($datasetA->isIsomorphicTo($datasetB, $partial));
        self::assertSame(['b2' => 'y', 'b1' => 'x'], $partial);

        $partial = [];
        self::assertTrue($datasetC->isIsomorphicTo($datasetD, $partial));
        self::assertSame(['b1' => 'x', 'b2' => 'y'], $partial);

        $partial = [];
        self::assertTrue($datasetA->isIsomorphicTo($datasetA, $partial));
        self::assertSame(['b2' => 'b2', 'b1' => 'b1'], $partial);
    }
}
