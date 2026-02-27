<?php

declare(strict_types=1);

namespace FancyRDF\Tests\W3CRdf11Tests;

use AssertionError;
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
 * Implements the N-Quads W3C test suite.
 *
 * @see https://www.w3.org/2013/N-QuadsTests/
 */
final class NQuadsTest extends TestBase
{
    /** @return array{string, string} */
    #[Override]
    protected static function getSuiteNameAndBaseUri(): array
    {
        return [
            'rdf11' . DIRECTORY_SEPARATOR . 'rdf-n-quads',
            'https://w3c.github.io/rdf-tests/rdf/rdf11/rdf-n-quads/',
        ];
    }

    #[TestDox('manifest loaded correct case counts')]
    #[Group('manifest')]
    public function testCaseCounts(): void
    {
        self::assertArraysAreIdenticalIgnoringOrder(
            self::caseCount(),
            [
                'http://www.w3.org/ns/rdftest#TestNQuadsNegativeSyntax' => 34,
                'http://www.w3.org/ns/rdftest#TestNQuadsPositiveSyntax' => 53,
            ],
        );
    }

    /** @return Generator<string, array{action: string}, mixed, void> */
    public static function nquadsPositiveSyntaxProvider(): Generator
    {
        foreach (self::cases('http://www.w3.org/ns/rdftest#TestNQuadsPositiveSyntax') as $info) {
            yield $info['iri'] => [
                'action' => $info['action'],
            ];
        }
    }

    /** @return Generator<string, array{action: string}, mixed, void> */
    public static function nquadsNegativeSyntaxProvider(): Generator
    {
        foreach (self::cases('http://www.w3.org/ns/rdftest#TestNQuadsNegativeSyntax') as $info) {
            yield $info['iri'] => [
                'action' => $info['action'],
            ];
        }
    }

    #[DataProvider('nquadsPositiveSyntaxProvider')]
    #[TestDox('$_dataname parses')]
    #[Group('positive-syntax')]
    public function testNQuadsPositiveSyntax(
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

    #[DataProvider('nquadsNegativeSyntaxProvider')]
    #[TestDox('$_dataname asserts with assertions enabled')]
    #[RequiresSetting('zend.assertions', '1')]
    #[Group('negative-syntax-strict')]
    public function testNQuadsNegativeSyntaxWithAssertions(
        string $action,
    ): void {
        $source = self::assertOpen($action);

        self::expectException(AssertionError::class);
        try {
            $parser = NFormatParser::parseStream($source);
            iterator_to_array($parser);
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }
    }

    #[DataProvider('nquadsNegativeSyntaxProvider')]
    #[TestDox('$_dataname does not throw with assertions disabled')]
    #[RequiresSetting('zend.assertions', '0')]
    #[Group('negative-syntax-lenient')]
    public function testNQuadsNegativeSyntaxWithoutAssertions(
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
