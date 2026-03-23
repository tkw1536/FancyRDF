<?php

declare(strict_types=1);

namespace FancyRDF\Tests\W3CRdf11Tests;

use AssertionError;
use FancyRDF\Exceptions\NonCompliantInputError;
use FancyRDF\Formats\RdfXmlParser;
use Generator;
use InvalidArgumentException;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresSetting;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;
use XMLReader;

use function fclose;
use function is_resource;
use function iterator_to_array;

use const DIRECTORY_SEPARATOR;

/**
 * Implements the RDF/XML W3C test suite.
 *
 * @see https://www.w3.org/2013/RDFXMLTests/
 */
final class RDFXMLTest extends TestBase
{
    /** @return array{string, string} */
    #[Override]
    protected static function getSuiteNameAndBaseUri(): array
    {
        return [
            'rdf11' . DIRECTORY_SEPARATOR . 'rdf-xml',
            'https://w3c.github.io/rdf-tests/rdf/rdf11/rdf-xml/',
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
                'http://www.w3.org/ns/rdftest#TestXMLEval' => 126,
                'http://www.w3.org/ns/rdftest#TestXMLNegativeSyntax' => 40,
            ],
        );
    }

    /**
     * @return Generator<string, array{action: string, result: string}, mixed, void>
     *
     * @throws RuntimeException
     * @throws NonCompliantInputError
     */
    public static function xmlTestEvaluationProvider(): Generator
    {
        foreach (self::cases('http://www.w3.org/ns/rdftest#TestXMLEval') as $info) {
            if ($info['result'] === null) {
                throw new RuntimeException('result is required for evaluation tests');
            }

            yield $info['iri'] => [
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
    public static function xmlTestNegativeSyntaxProvider(): Generator
    {
        foreach (self::cases('http://www.w3.org/ns/rdftest#TestXMLNegativeSyntax') as $info) {
            yield $info['iri'] => [
                'action' => $info['action'],
            ];
        }
    }

    /**
     * @throws RuntimeException
     * @throws InvalidArgumentException
     * @throws NonCompliantInputError
     */
    #[DataProvider('xmlTestEvaluationProvider')]
    #[TestDox('$_dataname')]
    #[Group('positive-evaluation')]
    public function testXMLTestEvaluation(
        string $action,
        string $result,
    ): void {
        $evaluation = self::makeEvaluationConstraint($result);

        $source = self::assertOpen($action);

        try {
            $reader = XMLReader::fromStream($source, null, 0, $action);
            $parser = new RdfXmlParser($reader);

            $got = iterator_to_array($parser);
            self::assertThat($got, $evaluation);
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
    #[DataProvider('xmlTestNegativeSyntaxProvider')]
    #[TestDox('$_dataname asserts with assertions enabled')]
    #[RequiresSetting('zend.assertions', '1')]
    #[Group('negative-syntax-strict')]
    public function testXMLTestNegativeSyntaxWithAssertions(
        string $action,
    ): void {
        $source = self::assertOpen($action);

        self::expectException(AssertionError::class);

        try {
            $reader = XMLReader::fromStream($source, null, 0, $action);
            $parser = new RdfXmlParser($reader);

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
    #[DataProvider('xmlTestNegativeSyntaxProvider')]
    #[TestDox('$_dataname does not assert with assertions enabled')]
    #[RequiresSetting('zend.assertions', '0')]
    #[Group('negative-syntax-lenient')]
    public function testXMLTestNegativeSyntaxWithoutAssertions(
        string $action,
    ): void {
        $source = self::assertOpen($action);

        try {
            $reader = XMLReader::fromStream($source, null, 0, $action);
            $parser = new RdfXmlParser($reader);

            iterator_to_array($parser);
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }
    }
}
