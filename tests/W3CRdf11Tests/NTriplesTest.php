<?php

declare(strict_types=1);

namespace FancyRDF\Tests\W3CRdf11Tests;

use AssertionError;
use FancyRDF\Dataset\Quad;
use FancyRDF\Formats\NFormatParser;
use Generator;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresSetting;
use PHPUnit\Framework\Attributes\TestDox;

use function fclose;
use function is_resource;
use function iterator_to_array;

use const DIRECTORY_SEPARATOR;

/**
 * Implements the N-Triples W3C test suite.
 *
 * @see https://www.w3.org/2013/N-TriplesTests/
 */
final class NTriplesTest extends TestBase
{
    /** @return array{string, string} */
    #[Override]
    protected static function getSuiteNameAndBaseUri(): array
    {
        return [
            'rdf11' . DIRECTORY_SEPARATOR . 'rdf-n-triples',
            'https://w3c.github.io/rdf-tests/rdf/rdf11/rdf-n-triples/',
        ];
    }

    #[TestDox('manifest loaded correct case counts')]
    #[Group('manifest')]
    public function testCaseCounts(): void
    {
        self::assertArraysAreIdenticalIgnoringOrder(
            self::caseCount(),
            [
                'http://www.w3.org/ns/rdftest#TestNTriplesNegativeSyntax' => 29,
                'http://www.w3.org/ns/rdftest#TestNTriplesPositiveSyntax' => 41,
            ],
        );
    }

    /** @return Generator<string, array{action: string}, mixed, void> */
    public static function ntriplesPositiveSyntaxProvider(): Generator
    {
        foreach (self::cases('http://www.w3.org/ns/rdftest#TestNTriplesPositiveSyntax') as $info) {
            yield $info['iri'] => [
                'action' => $info['action'],
            ];
        }
    }

    /** @return Generator<string, array{action: string}, mixed, void> */
    public static function ntriplesNegativeSyntaxProvider(): Generator
    {
        foreach (self::cases('http://www.w3.org/ns/rdftest#TestNTriplesNegativeSyntax') as $info) {
            yield $info['iri'] => [
                'action' => $info['action'],
            ];
        }
    }

    #[DataProvider('ntriplesPositiveSyntaxProvider')]
    #[TestDox('$_dataname parses')]
    #[Group('positive-syntax')]
    public function testNTriplesPositiveSyntax(
        string $action,
    ): void {
        $source = self::assertOpen($action);

        try {
            $parser = NFormatParser::parseStream($source);

            foreach ($parser as $statement) {
                self::assertSame(3, Quad::size($statement));
            }
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }
    }

    #[DataProvider('ntriplesNegativeSyntaxProvider')]
    #[TestDox('$_dataname asserts with assertions enabled')]
    #[RequiresSetting('zend.assertions', '1')]
    #[Group('negative-syntax-strict')]
    public function testNTriplesNegativeSyntaxWithAssertions(
        string $action,
    ): void {
        $source = self::assertOpen($action);
        self::expectException(AssertionError::class);

        try {
            $parser = NFormatParser::parseStream($source);

            foreach ($parser as $statement) {
                self::assertSame(4, Quad::size($statement));
            }
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }
    }

    #[DataProvider('ntriplesNegativeSyntaxProvider')]
    #[TestDox('$_dataname does not throw with assertions disabled')]
    #[RequiresSetting('zend.assertions', '0')]
    #[Group('negative-syntax-lenient')]
    public function testNTriplesNegativeSyntaxWithoutAssertions(
        string $action,
    ): void {
        $source = self::assertOpen($action);

        try {
            $parser = NFormatParser::parseStream($source);
            iterator_to_array($parser);
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }
    }
}
