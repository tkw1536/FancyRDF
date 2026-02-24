<?php

declare(strict_types=1);

namespace FancyRDF\Tests\W3CRdf11Tests;

use FancyRDF\Dataset\Quad;
use FancyRDF\Formats\NFormatParser;
use FancyRDF\Term\Iri;
use FancyRDF\Tests\Support\IsomorphicAsDatasetsConstraint;
use FancyRDF\Tests\Support\Rdf11TestCases;
use Generator;
use PHPUnit\Framework\Attributes\BeforeClass;
use PHPUnit\Framework\TestCase;

use function dirname;
use function fclose;
use function fopen;
use function implode;
use function is_resource;
use function iterator_to_array;

use const DIRECTORY_SEPARATOR;

/**
 * Base class for RDF 1.1 test cases.
 *
 * This test class defines several test groups:
 *
 * - 'manifest': Checks that the manifest loads properly.
 * - 'positive-syntax': Checks that a parser or lexer can pass a positive syntax test.
 * - 'negative-syntax-strict': Checks that a parser can pass a negative syntax test and asserts in production mode.
 * - 'negative-syntax-lenient': Checks that a parser can parse a negative syntax file, and does not crash in development mode.
 * - 'positive-evaluation': Checks that a parser passes a positive evaluation test.
 *
 * Each child class should define the test groups that it uses.
 *
 * @see https://www.w3.org/TR/rdf11-testcases/
 *
 * @phpstan-import-type TripleOrQuadArray from Quad
 */
abstract class TestBase extends TestCase
{
    /** @var array<string, Rdf11TestCases> */
    private static array $casesBySuite = [];

    abstract protected static function getTestSuiteName(): string;

    #[BeforeClass()]
    public static function ensureManifestLoaded(): void
    {
        $testSuiteName = static::getTestSuiteName();

        if (isset(self::$casesBySuite[$testSuiteName])) {
            return;
        }

        self::$casesBySuite[$testSuiteName] = new Rdf11TestCases(
            implode(
                DIRECTORY_SEPARATOR,
                [
                    dirname(__FILE__),
                    'testdata',
                    'rdf11',
                    $testSuiteName,
                    'manifest.ttl',
                ],
            ),
            'https://w3c.github.io/rdf-tests/rdf/rdf11/' . $testSuiteName . '/manifest.ttl',
        );
    }

    private static function casesInstance(): Rdf11TestCases
    {
        self::ensureManifestLoaded();
        $testSuiteName = static::getTestSuiteName();

        return self::$casesBySuite[$testSuiteName];
    }

    /**
     * Yields test cases for the given type.
     *
     * @param non-empty-string $typ
     *   The IRI of the type of the test cases to load.
     *
     * @return Generator<int, array{iri: string, name: string, comment: string|null, action: string, result: string|null}, mixed, void>
     */
    protected static function cases(string $typ): Generator
    {
        yield from self::casesInstance()->loadEntries(new Iri($typ));
    }

    /**
     * Opens a file for the given iri and asserts that it can be opened correctly.
     *
     * @return resource
     */
    protected static function assertOpen(string $iri)
    {
        $filePath = self::casesInstance()->resolve($iri);
        self::assertNotNull($filePath, 'file for iri not found: ' . $iri);

        $source = fopen($filePath, 'r');
        self::assertNotFalse($source, 'failed to open file for iri: ' . $filePath);

        return $source;
    }

    /**
     * Loads an evaluation file and returns an appropriate constraint.
     */
    protected static function makeEvaluationConstraint(string $iri): IsomorphicAsDatasetsConstraint
    {
        $filePath = self::casesInstance()->resolve($iri);
        if ($filePath === null) {
            self::fail('evaluation file not found: ' . $iri);
        }

        $source = fopen($filePath, 'r');
        if ($source === false) {
            self::fail('failed to open evaluation file: ' . $filePath);
        }

        try {
            $parser = NFormatParser::parseStream($source);
            $quads  = iterator_to_array($parser);

            return new IsomorphicAsDatasetsConstraint($quads, false);
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }
    }

    /**
     * Counts the types of entries found in the manifest.
     *
     * @return array<string, int>
     *   The number of entries for each type.
     *   The array is sorted by key.
     */
    public static function caseCount(): array
    {
        return self::casesInstance()->countEntryTypes();
    }
}
