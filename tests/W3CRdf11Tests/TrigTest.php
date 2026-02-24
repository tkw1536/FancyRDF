<?php

declare(strict_types=1);

namespace FancyRDF\Tests\W3CRdf11Tests;

use AssertionError;
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

/**
 * Implements the Trig W3C test suite.
 *
 * @see https://www.w3.org/2013/TrigTests/
 */
final class TrigTest extends TestBase
{
    #[Override]
    protected static function getTestSuiteName(): string
    {
        return 'rdf-trig';
    }

    #[TestDox('manifest loaded correct case counts')]
    #[Group('manifest')]
    public function testCaseCounts(): void
    {
        self::assertEquals(
            self::caseCount(),
            [
                'http://www.w3.org/ns/rdftest#TestTrigEval' => 143,
                'http://www.w3.org/ns/rdftest#TestTrigNegativeEval' => 4,
                'http://www.w3.org/ns/rdftest#TestTrigNegativeSyntax' => 111,
                'http://www.w3.org/ns/rdftest#TestTrigPositiveSyntax' => 98,
            ],
        );
    }

    /** @return Generator<string, array{action: string}, mixed, void> */
    public static function trigPositiveSyntaxProvider(): Generator
    {
        foreach (self::cases('http://www.w3.org/ns/rdftest#TestTrigPositiveSyntax') as $info) {
            yield $info['iri'] => [
                'action' => $info['action'],
            ];
        }
    }

    /** @return Generator<string, array{action: string, result: string}, mixed, void> */
    public static function trigEvaluationProvider(): Generator
    {
        foreach (self::cases('http://www.w3.org/ns/rdftest#TestTrigEval') as $info) {
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

    #[DataProvider('trigPositiveSyntaxProvider')]
    #[TestDox('$_dataname parses')]
    #[Group('positive-syntax')]
    public function testTrigPositiveSyntax(
        string $action,
    ): void {
        $source = self::assertOpen($action);

        try {
            $reader = new TrigReader(new ResourceStreamReader($source));
            $parser = new TrigParser($reader, true, $action);
            iterator_to_array($parser);
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }
    }

    #[DataProvider('trigNegativeSyntaxProvider')]
    #[TestDox('$_dataname asserts with assertions enabled')]
    #[RequiresSetting('zend.assertions', '1')]
    #[Group('negative-syntax-strict')]
    public function testTrigNegativeSyntaxWithAssertions(
        string $action,
    ): void {
        $source = self::assertOpen($action);
        self::expectException(AssertionError::class);

        try {
            $reader = new TrigReader(new ResourceStreamReader($source));
            $parser = new TrigParser($reader, true, $action);
            iterator_to_array($parser);
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }
    }

    #[DataProvider('trigNegativeSyntaxProvider')]
    #[TestDox('$_dataname does not throw with assertions disabled')]
    #[RequiresSetting('zend.assertions', '0')]
    #[Group('negative-syntax-lenient')]
    public function testTrigNegativeSyntaxWithoutAssertions(
        string $action,
    ): void {
        $source = self::assertOpen($action);

        try {
            $reader = new TrigReader(new ResourceStreamReader($source));
            $parser = new TrigParser($reader, true, $action);
            iterator_to_array($parser);
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }
    }

    #[DataProvider('trigEvaluationProvider')]
    #[TestDox('$_dataname evaluates correctly')]
    #[Group('positive-evaluation')]
    public function testTrigEvaluation(
        string $action,
        string $result,
    ): void {
        $evaluation = self::makeEvaluationConstraint($result);
        $source     = self::assertOpen($action);

        try {
            $reader = new TrigReader(new ResourceStreamReader($source));
            $parser = new TrigParser($reader, true, $action);

            $got = iterator_to_array($parser);

            self::assertThat($got, $evaluation);
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }
    }
}
