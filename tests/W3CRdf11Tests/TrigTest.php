<?php

declare(strict_types=1);

namespace FancyRDF\Tests\W3CRdf11Tests;

use FancyRDF\Exceptions\NonCompliantInputError;
use FancyRDF\Formats\TrigParser;
use FancyRDF\Formats\TrigReader\TrigReader;
use FancyRDF\Streaming\ResourceStreamReader;
use Generator;
use InvalidArgumentException;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;

use function fclose;
use function is_resource;
use function iterator_to_array;

use const DIRECTORY_SEPARATOR;

/**
 * Implements the Trig W3C test suite.
 *
 * @see https://www.w3.org/2013/TrigTests/
 */
final class TrigTest extends TestBase
{
    /** @return array{string, string} */
    #[Override]
    protected static function getSuiteNameAndBaseUri(): array
    {
        return [
            'rdf11' . DIRECTORY_SEPARATOR . 'rdf-trig',
            'https://w3c.github.io/rdf-tests/rdf/rdf11/rdf-trig/',
        ];
    }

    /**
     * @throws RuntimeException
     * @throws NonCompliantInputError
     */
    #[TestDox('manifest loaded correct case counts')]
    #[Group('manifest')]
    public function testCaseCounts(): void
    {
        self::assertArraysAreIdenticalIgnoringOrder(
            self::caseCount(),
            [
                'http://www.w3.org/ns/rdftest#TestTrigEval' => 143,
                'http://www.w3.org/ns/rdftest#TestTrigNegativeEval' => 4,
                'http://www.w3.org/ns/rdftest#TestTrigNegativeSyntax' => 111,
                'http://www.w3.org/ns/rdftest#TestTrigPositiveSyntax' => 98,
            ],
        );
    }

    /**
     * @return Generator<string, array{strict: bool, action: string}, mixed, void>
     *
     * @throws RuntimeException
     * @throws NonCompliantInputError
     */
    public static function trigPositiveSyntaxProvider(): Generator
    {
        foreach (self::cases('http://www.w3.org/ns/rdftest#TestTrigPositiveSyntax') as $info) {
            yield 'strict/' . $info['iri'] => [
                'strict' => true,
                'action' => $info['action'],
            ];

            yield 'loose/' . $info['iri'] => [
                'strict' => false,
                'action' => $info['action'],
            ];
        }
    }

    /**
     * @return Generator<string, array{strict: bool, action: string, result: string}, mixed, void>
     *
     * @throws RuntimeException
     * @throws NonCompliantInputError
     */
    public static function trigEvaluationProvider(): Generator
    {
        foreach (self::cases('http://www.w3.org/ns/rdftest#TestTrigEval') as $info) {
            if ($info['result'] === null) {
                throw new RuntimeException('result is required for evaluation tests');
            }

            yield 'strict/' . $info['iri'] => [
                'strict' => true,
                'action' => $info['action'],
                'result' => $info['result'],
            ];

            yield 'loose/' . $info['iri'] => [
                'strict' => false,
                'action' => $info['action'],
                'result' => $info['result'],
            ];
        }
    }

    /**
     * @return Generator<string, array{action: string}, mixed, void>
     *
     * @throws RuntimeException
     * @throws NonCompliantInputError
     */
    public static function trigNegativeSyntaxProvider(): Generator
    {
        foreach (self::cases('http://www.w3.org/ns/rdftest#TestTrigNegativeSyntax') as $info) {
            yield $info['iri'] => [
                'action' => $info['action'],
            ];
        }

        // Same as in the turtle tests these seem to have the same properties as rdft:TestTrigNegativeSyntax.
        // So we put them into this group again.
        //
        // Not exactly sure why, I would have expected there to be an nquads file that it's not equal to.
        foreach (self::cases('http://www.w3.org/ns/rdftest#TestTrigNegativeEval') as $info) {
            yield $info['iri'] => [
                'action' => $info['action'],
            ];
        }
    }

    /**
     * @throws RuntimeException
     * @throws NonCompliantInputError
     */
    #[DataProvider('trigPositiveSyntaxProvider')]
    #[TestDox('$_dataname parses')]
    #[Group('positive-syntax')]
    public function testTrigPositiveSyntax(
        bool $strict,
        string $action,
    ): void {
        $source = self::assertOpen($action);

        try {
            $reader = new TrigReader($strict, new ResourceStreamReader($source));
            $parser = new TrigParser($strict, $reader, true, $action);
            iterator_to_array($parser);
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }
    }

    /**
     * @throws RuntimeException
     * @throws NonCompliantInputError
     */
    #[DataProvider('trigNegativeSyntaxProvider')]
    #[TestDox('$_dataname asserts with strict mode enabled')]
    #[Group('negative-syntax-strict')]
    public function testTrigNegativeSyntaxWithAssertionsStrict(
        string $action,
    ): void {
        $source = self::assertOpen($action);
        self::expectException(NonCompliantInputError::class);

        try {
            $reader = new TrigReader(true, new ResourceStreamReader($source));
            $parser = new TrigParser(true, $reader, true, $action);
            iterator_to_array($parser);
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }
    }

    /**
     * @throws RuntimeException
     * @throws NonCompliantInputError
    */
    #[DataProvider('trigNegativeSyntaxProvider')]
    #[TestDox('$_dataname does not throw without strict mode disabled')]
    #[Group('negative-syntax-lenient')]
    public function testTrigNegativeSyntaxLoose(
        string $action,
    ): void {
        $source = self::assertOpen($action);

        try {
            $reader = new TrigReader(false, new ResourceStreamReader($source));
            $parser = new TrigParser(false, $reader, true, $action);
            iterator_to_array($parser);
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }
    }

    /**
     * @throws RuntimeException
     * @throws InvalidArgumentException
     * @throws NonCompliantInputError
    */
    #[DataProvider('trigEvaluationProvider')]
    #[TestDox('$_dataname evaluates correctly')]
    #[Group('positive-evaluation')]
    public function testTrigEvaluation(
        bool $strict,
        string $action,
        string $result,
    ): void {
        $evaluation = self::makeEvaluationConstraint($result);
        $source     = self::assertOpen($action);

        try {
            $reader = new TrigReader($strict, new ResourceStreamReader($source));
            $parser = new TrigParser($strict, $reader, true, $action);

            $got = iterator_to_array($parser);

            self::assertThat($got, $evaluation);
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }
    }
}
