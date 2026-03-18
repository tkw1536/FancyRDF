<?php

declare(strict_types=1);

namespace FancyRDF\Tests\Support;

use RuntimeException;

use function basename;
use function is_dir;
use function is_file;
use function scandir;

use const DIRECTORY_SEPARATOR;

/**
 * Helper for discovering local RDF testcases in multiple concrete syntaxes.
 *
 * Each testcase lives in its own subdirectory of the given base directory and may
 * contain any of the following files:
 *
 * - $name . '.nt'               Triple dataset in N-Triples format
 * - $name . '.nq'               Quad dataset in N-Quads format
 * - $name . '.ttl'              Triple dataset in Turtle / TriG format
 * - $name . '.trig'             Quad dataset in TriG format
 * - $name . '.rdf'              RDF/XML which should de‑serialize into the triple dataset
 * - $name . '-serialized.rdf'   RDF/XML that serializers should output for the triples
 *
 * This class exposes a single static method returning all discovered testcases with
 * absolute paths (or null) for each of the known file types.
 */
final class LocalRdfTestCases
{
    /**
     * @return array<string, array{
     *     nt: string|null,
     *     nq: string|null,
     *     ttl: string|null,
     *     trig: string|null,
     *     rdf: string|null,
     *     rdf_serialized: string|null
     * }>
     *
     * @throws RuntimeException
     */
    public static function load(string $baseDir): array
    {
        if (! is_dir($baseDir)) {
            throw new RuntimeException('Base directory does not exist: ' . $baseDir);
        }

        $entries = scandir($baseDir);
        if ($entries === false) {
            throw new RuntimeException('Failed to read directory: ' . $baseDir);
        }

        $cases = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $caseDir = $baseDir . DIRECTORY_SEPARATOR . $entry;
            if (! is_dir($caseDir)) {
                continue;
            }

            $name = basename($caseDir);

            $ntFile        = $caseDir . DIRECTORY_SEPARATOR . $name . '.nt';
            $nqFile        = $caseDir . DIRECTORY_SEPARATOR . $name . '.nq';
            $ttlFile       = $caseDir . DIRECTORY_SEPARATOR . $name . '.ttl';
            $trigFile      = $caseDir . DIRECTORY_SEPARATOR . $name . '.trig';
            $rdfFile       = $caseDir . DIRECTORY_SEPARATOR . $name . '.rdf';
            $serializedRdf = $caseDir . DIRECTORY_SEPARATOR . $name . '-serialized.rdf';

            $cases[$name] = [
                'nt' => is_file($ntFile) ? $ntFile : null,
                'nq' => is_file($nqFile) ? $nqFile : null,
                'ttl' => is_file($ttlFile) ? $ttlFile : null,
                'trig' => is_file($trigFile) ? $trigFile : null,
                'rdf' => is_file($rdfFile) ? $rdfFile : null,
                'rdf_serialized' => is_file($serializedRdf) ? $serializedRdf : null,
            ];
        }

        return $cases;
    }
}
