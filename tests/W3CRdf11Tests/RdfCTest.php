<?php

declare(strict_types=1);

namespace FancyRDF\Tests\W3CRdf11Tests;

use FancyRDF\Dataset\Dataset;
use FancyRDF\Dataset\RdfCanon\CanonicalizationLimitExceeded;
use FancyRDF\Dataset\RdfCanon\RdfCanonicalizationOptions;
use FancyRDF\Dataset\RdfCanon\RdfCanonicalizer;
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
use function json_decode;
use function ksort;
use function stream_get_contents;

/**
 * Implements the RDFC-1.0 W3C test suite.
 *
 * @see https://w3c.github.io/rdf-canon/tests/
 */
final class RdfCTest extends TestBase
{
    /** @return array{string, string} */
    #[Override]
    protected static function getSuiteNameAndBaseUri(): array
    {
        return [
            'rdf-canon',
            'https://w3c.github.io/rdf-canon/tests/',
        ];
    }

    #[Override]
    protected static function getManifestUri(): string
    {
        $testSuiteBaseUri = static::getSuiteNameAndBaseUri()[1];

        return $testSuiteBaseUri . 'manifest';
    }

    #[TestDox('manifest loaded correct case counts')]
    #[Group('manifest')]
    public function testCaseCounts(): void
    {
        self::assertEquals(
            self::caseCount(),
            [
                'https://w3c.github.io/rdf-canon/tests/vocab#RDFC10EvalTest' => 64,
                'https://w3c.github.io/rdf-canon/tests/vocab#RDFC10MapTest' => 21,
                'https://w3c.github.io/rdf-canon/tests/vocab#RDFC10NegativeEvalTest' => 1,
            ],
        );
    }

    public const string HASH_ALGORITHM_PROPERTY = 'https://w3c.github.io/rdf-canon/tests/vocab#hashAlgorithm';

    /** @return Generator<string, array{action: string, result: string, algorithm: string}, mixed, void> */
    public static function rdfc10EvalTestProvider(): Generator
    {
        foreach (self::cases('https://w3c.github.io/rdf-canon/tests/vocab#RDFC10EvalTest', [self::HASH_ALGORITHM_PROPERTY]) as $info) {
            if ($info['result'] === null) {
                throw new RuntimeException('result is required for evaluation tests');
            }

            $hashAlgorithm = $info['extra'][self::HASH_ALGORITHM_PROPERTY] ?? 'sha256';
            if ($hashAlgorithm === '') {
                throw new RuntimeException('hash algorithm is required for evaluation tests');
            }

            yield $info['iri'] => [
                'action' => $info['action'],
                'result' => $info['result'],
                'algorithm' => $hashAlgorithm,
            ];
        }
    }

    /** @return Generator<string, array{action: string, result: string, algorithm: string}, mixed, void> */
    public static function rdfc10MapTestProvider(): Generator
    {
        foreach (self::cases('https://w3c.github.io/rdf-canon/tests/vocab#RDFC10MapTest', [self::HASH_ALGORITHM_PROPERTY]) as $info) {
            if ($info['result'] === null) {
                throw new RuntimeException('result is required for evaluation tests');
            }

            $hashAlgorithm = $info['extra'][self::HASH_ALGORITHM_PROPERTY] ?? 'sha256';
            if ($hashAlgorithm === '') {
                throw new RuntimeException('hash algorithm is required for evaluation tests');
            }

            yield $info['iri'] => [
                'action' => $info['action'],
                'result' => $info['result'],
                'algorithm' => $hashAlgorithm,
            ];
        }
    }

    /** @return Generator<string, array{action: string, algorithm: string}, mixed, void> */
    public static function rdfc10NegativeEvalTestProvider(): Generator
    {
        foreach (self::cases('https://w3c.github.io/rdf-canon/tests/vocab#RDFC10NegativeEvalTest', [self::HASH_ALGORITHM_PROPERTY]) as $info) {
            $hashAlgorithm = $info['extra'][self::HASH_ALGORITHM_PROPERTY] ?? 'sha256';
            if ($hashAlgorithm === '') {
                throw new RuntimeException('hash algorithm is required for evaluation tests');
            }

            yield $info['iri'] => [
                'action' => $info['action'],
                'algorithm' => $hashAlgorithm,
            ];
        }
    }

    /** @param non-empty-string $algorithm */
    #[TestDox('$_dataName evaluates to the correct result')]
    #[DataProvider('rdfc10EvalTestProvider')]
    #[Group('positive-eval')]
    public function testRdfc10EvalTest(string $action, string $result, string $algorithm): void
    {
        // Parse the action stream as n-quads
        $action = self::assertOpen($action);
        $input  = [];
        try {
            $input = new Dataset(iterator_to_array(NFormatParser::parseStream($action)));
        } finally {
            if (is_resource($action)) {
                fclose($action);
            }
        }

        // Parse the results stream as n-quads
        $result = self::assertOpen($result);
        try {
            $expected = stream_get_contents($result);
        } finally {
            if (is_resource($result)) {
                fclose($result);
            }
        }

        $canonicalizer = new RdfCanonicalizer(new RdfCanonicalizationOptions($algorithm));
        $result        = $canonicalizer->canonicalize($input);

        self::assertSame($expected, $result->toCanonicalNQuads());
    }

    /** @param non-empty-string $algorithm */
    #[TestDox('$_dataName hits limits when trying to canonicalize')]
    #[DataProvider('rdfc10NegativeEvalTestProvider')]
    #[Group('negative-eval')]
    public function testRdfc10NegativeEvalTest(string $action, string $algorithm): void
    {
        // Parse the action stream as n-quads
        $action = self::assertOpen($action);
        $input  = [];
        try {
            $input = new Dataset(iterator_to_array(NFormatParser::parseStream($action)));
        } finally {
            if (is_resource($action)) {
                fclose($action);
            }
        }

        self::expectException(CanonicalizationLimitExceeded::class);

        // Per [1] "the test passes if the implementation generates an error due to excessive calls to Hash N-Degree Quads".
        // The documentation isn't specific as to what 'excessive calls' actually means.
        // So we explicitly remove all limits except for 'maxHashNDegreeQuadCalls'.
        //
        // [1]: https://w3c.github.io/rdf-canon/tests/#tests-for-rdfc-10-take-input-files-specified-as-n-quads-and-generate-canonical-n-quads-output-as-required-by-the-rdfc-10-algorithm
        $options = new RdfCanonicalizationOptions($algorithm, null, null, 10_000, null);

        $canonicalizer = new RdfCanonicalizer($options);
        $canonicalizer->canonicalize($input);
    }

    /** @param non-empty-string $algorithm */
    #[TestDox('$_dataName produces the correct blank node map')]
    #[DataProvider('rdfc10MapTestProvider')]
    #[Group('positive-eval')]
    public function testRdfc10MapTest(string $action, string $result, string $algorithm): void
    {
        // Parse the action stream as n-quads
        $action = self::assertOpen($action);
        $input  = [];
        try {
            $input = new Dataset(iterator_to_array(NFormatParser::parseStream($action)));
        } finally {
            if (is_resource($action)) {
                fclose($action);
            }
        }

        $expected = json_decode(self::assertRead($result), true);
        self::assertIsArray($expected, 'result is not a valid JSON array');

        $canonicalizer = new RdfCanonicalizer(new RdfCanonicalizationOptions($algorithm));
        $result        = $canonicalizer->canonicalize($input);

        // need to compare without order of keys!
        $got = $result->blankNodeMap;
        ksort($got);
        ksort($expected);

        self::assertSame($expected, $got);
    }
}
