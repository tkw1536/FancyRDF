<?php

declare(strict_types=1);

namespace FancyRDF\Tests\W3CRdf11Tests;

use AssertionError;
use FancyRDF\Dataset\Quad;
use FancyRDF\Formats\TrigParser;
use FancyRDF\Formats\TrigReader\TrigReader;
use FancyRDF\Streaming\ResourceStreamReader;
use Generator;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresSetting;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;

use function fclose;
use function is_resource;
use function iterator_to_array;

use const DIRECTORY_SEPARATOR;

/**
 * Implements the Turtle W3C test suite.
 *
 * @see https://www.w3.org/2013/TurtleTests/
 */
final class TurtleTest extends TestBase
{
    /** @return array{string, string} */
    #[Override]
    protected static function getSuiteNameAndBaseUri(): array
    {
        return [
            'rdf11' . DIRECTORY_SEPARATOR . 'rdf-turtle',
            'https://w3c.github.io/rdf-tests/rdf/rdf11/rdf-turtle/',
        ];
    }

    #[TestDox('manifest loaded correct case counts')]
    #[Group('manifest')]
    public function testCaseCounts(): void
    {
        self::assertEquals(
            self::caseCount(),
            [
                'http://www.w3.org/ns/rdftest#TestTurtleEval' => 145,
                'http://www.w3.org/ns/rdftest#TestTurtleNegativeEval' => 4,
                'http://www.w3.org/ns/rdftest#TestTurtleNegativeSyntax' => 90,
                'http://www.w3.org/ns/rdftest#TestTurtlePositiveSyntax' => 74,
            ],
        );
    }

    /** @return Generator<string, array{action: string}, mixed, void> */
    public static function turtlePositiveSyntaxProvider(): Generator
    {
        foreach (self::cases('http://www.w3.org/ns/rdftest#TestTurtlePositiveSyntax') as $info) {
            yield $info['iri'] => [
                'action' => $info['action'],
            ];
        }
    }

    /** @return Generator<string, array{action: string, result: string}, mixed, void> */
    public static function turtleEvaluationProvider(): Generator
    {
        foreach (self::cases('http://www.w3.org/ns/rdftest#TestTurtleEval') as $info) {
            if ($info['result'] === null) {
                throw new RuntimeException('result is required for evaluation tests');
            }

            yield $info['iri'] => [
                'action' => $info['action'],
                'result' => $info['result'],
            ];
        }
    }

    /** @return Generator<string, array{action: string}, mixed, void> */
    public static function turtleNegativeSyntaxProvider(): Generator
    {
        foreach (self::cases('http://www.w3.org/ns/rdftest#TestTurtleNegativeSyntax') as $info) {
            yield $info['iri'] => [
                'action' => $info['action'],
            ];
        }

        // Per [1] "these tests have the same properties as rdft:TestTurtleNegativeSyntax".
        // Not exactly sure why, I would have expected there to be an ntriples file that it's not equal to.
        // Either way, we group them with the negative syntax tests.
        //
        // [1]: https://www.w3.org/2013/TurtleTests/README
        foreach (self::cases('http://www.w3.org/ns/rdftest#TestTurtleNegativeEval') as $info) {
            yield $info['iri'] => [
                'action' => $info['action'],
            ];
        }
    }

    #[DataProvider('turtlePositiveSyntaxProvider')]
    #[TestDox('$_dataname parses')]
    #[Group('positive-syntax')]
    public static function testTurtlePositiveSyntax(
        string $action,
    ): void {
        $source = self::assertOpen($action);

        try {
            $reader = new TrigReader(new ResourceStreamReader($source));
            $parser = new TrigParser($reader, false);

            foreach ($parser as $statement) {
                self::assertEquals(Quad::size($statement), 3);
            }
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }
    }

    #[DataProvider('turtleEvaluationProvider')]
    #[TestDox('$_dataname evaluates correctly')]
    #[Group('positive-evaluation')]
    public function testTurtleEvaluation(
        string $action,
        string $result,
    ): void {
        $evaluation = $this->makeEvaluationConstraint($result);

        $source = self::assertOpen($action);

        try {
            $reader = new TrigReader(new ResourceStreamReader($source));
            $parser = new TrigParser($reader, false, $action);

            $got = iterator_to_array($parser);
            self::assertThat($got, $evaluation);
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }
    }

    #[DataProvider('turtleNegativeSyntaxProvider')]
    #[TestDox('$_dataname asserts with assertions enabled')]
    #[RequiresSetting('zend.assertions', '1')]
    #[Group('negative-syntax-strict')]
    public function testTurtleNegativeSyntaxWithAssertions(
        string $action,
    ): void {
        $source = self::assertOpen($action);

        self::expectException(AssertionError::class);

        try {
            $reader = new TrigReader(new ResourceStreamReader($source));
            $parser = new TrigParser($reader, false, $action);
            iterator_to_array($parser);
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }
    }

    #[DataProvider('turtleNegativeSyntaxProvider')]
    #[RequiresSetting('zend.assertions', '0')]
    #[TestDox('$_dataname does not assert with assertions disabled')]
    #[Group('negative-syntax-lenient')]
    public function testTurtleNegativeSyntaxWithoutAssertions(
        string $action,
    ): void {
        $source = self::assertOpen($action);

        try {
            $reader = new TrigReader(new ResourceStreamReader($source));
            $parser = new TrigParser($reader, false, $action);
            iterator_to_array($parser);
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }
    }
}
