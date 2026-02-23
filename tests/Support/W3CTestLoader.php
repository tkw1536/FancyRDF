<?php

declare(strict_types=1);

namespace FancyRDF\Tests\Support;

use Exception;

use function count;
use function dirname;
use function file_get_contents;
use function glob;
use function implode;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr;

use const DIRECTORY_SEPARATOR;

final class W3CTestLoader
{
    /** you cannot instantiate this class */
    private function __construct()
    {
    }

    private static function getDocumentBase(string $path): string|null
    {
        $baseMaps = [
            'rdf_tests' . DIRECTORY_SEPARATOR . 'rdfxml' . DIRECTORY_SEPARATOR => 'https://w3c.github.io/rdf-tests/rdf/rdf11/rdf-xml/',
            'rdf_tests' . DIRECTORY_SEPARATOR . 'trig' . DIRECTORY_SEPARATOR => 'https://w3c.github.io/rdf-tests/rdf/rdf11/rdf-trig/',
        ];

        foreach ($baseMaps as $prefix => $base) {
            $pos = strpos($path, $prefix);
            if ($pos === false) {
                continue;
            }

            $relativePath = substr($path, $pos + strlen($prefix));

            return $base . $relativePath;
        }

        return null;
    }

    /**
     * Loads a set of test files from a subdirectory of 'rdf_tests'.
     *
     * @param list<string> $patterns
     *
     * @return non-empty-array<string, array{string, string, string|null}>
     *   A data provider result in the form of [name => [path, content, documentPath]]
     */
    public static function load(array $patterns, string ...$paths): array
    {
        /** @var array<string, string> $files */
        $files = [];

        $basePath = implode(DIRECTORY_SEPARATOR, [
            dirname(__DIR__, 2),
            'rdf_tests',
            ...$paths,
        ]);

        $caseFiles = [];
        foreach ($patterns as $pattern) {
            $files = glob($basePath . DIRECTORY_SEPARATOR . $pattern);
            if ($files === false) {
                throw new Exception('Failed to read files: ' . $basePath . DIRECTORY_SEPARATOR . $pattern);
            }

            foreach ($files as $path) {
                if (! str_starts_with($path, $basePath . DIRECTORY_SEPARATOR)) {
                    throw new Exception('File ' . $path . ' is not in the base path ' . $basePath);
                }

                $name = substr($path, strlen($basePath . DIRECTORY_SEPARATOR));

                $caseFiles[$name] = $path;
            }
        }

        if (count($caseFiles) === 0) {
            throw new Exception('No test files found in ' . $basePath . ' matching ' . implode(' ', $patterns));
        }

        $cases = [];
        foreach ($caseFiles as $name => $path) {
            $content = file_get_contents($path);
            if ($content === false) {
                throw new Exception('Failed to read file: ' . $path);
            }

            $cases[$name] = [$path, $content, self::getDocumentBase($path)];
        }

        return $cases;
    }
}
