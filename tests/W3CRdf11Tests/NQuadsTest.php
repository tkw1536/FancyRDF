<?php

declare(strict_types=1);

namespace FancyRDF\Tests\W3CRdf11Tests;

use FancyRDF\Exceptions\NonCompliantInputError;
use FancyRDF\Formats\NFormatParser;
use Generator;
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
                'http://www.w3.org/ns/rdftest#TestNQuadsNegativeSyntax' => 34,
                'http://www.w3.org/ns/rdftest#TestNQuadsPositiveSyntax' => 53,
            ],
        );
    }

    /**
     * @return Generator<string, array{action: string}, mixed, void>
     *
     * @throws RuntimeException
     * @throws NonCompliantInputError
     */
    public static function nquadsPositiveSyntaxProvider(): Generator
    {
        foreach (self::cases('http://www.w3.org/ns/rdftest#TestNQuadsPositiveSyntax') as $info) {
            yield 'loose/' . $info['iri'] => [
                'strict' => false,
                'action' => $info['action'],
            ];

            yield 'strict/' . $info['iri'] => [
                'strict' => true,
                'action' => $info['action'],
            ];
        }
    }

    /**
     * @return Generator<string, array{action: string}, mixed, void>
     *
     * @throws RuntimeException
     * @throws NonCompliantInputError
     */
    public static function nquadsNegativeSyntaxProvider(): Generator
    {
        foreach (self::cases('http://www.w3.org/ns/rdftest#TestNQuadsNegativeSyntax') as $info) {
            yield $info['iri'] => [
                'action' => $info['action'],
            ];
        }
    }

    /**
     * @throws RuntimeException
     * @throws NonCompliantInputError
     */
    #[DataProvider('nquadsPositiveSyntaxProvider')]
    #[TestDox('$_dataname parses')]
    #[Group('positive-syntax')]
    public function testNQuadsPositiveSyntax(
        bool $strict,
        string $action,
    ): void {
        $source = self::assertOpen($action);

        try {
            $parser = new NFormatParser($strict);
            $parser = $parser->parseStream($source);
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
    #[DataProvider('nquadsNegativeSyntaxProvider')]
    #[TestDox('$_dataname asserts with assertions enabled')]
    #[Group('negative-syntax-strict')]
    public function testNQuadsNegativeSyntaxStrict(
        string $action,
    ): void {
        $source = self::assertOpen($action);

        self::expectException(NonCompliantInputError::class);
        try {
            $parser = new NFormatParser(true);
            $parser = $parser->parseStream($source);
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
    #[DataProvider('nquadsNegativeSyntaxProvider')]
    #[TestDox('$_dataname does not throw with assertions disabled')]
    #[Group('negative-syntax-lenient')]
    public function testNQuadsNegativeSyntaxLenient(
        string $action,
    ): void {
        $source = self::assertOpen($action);

        try {
            $parser = new NFormatParser(false);
            $parser = $parser->parseStream($source);
            iterator_to_array($parser);
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }
    }
}
