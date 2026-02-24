<?php

declare(strict_types=1);

namespace FancyRDF\Tests\Support;

use FancyRDF\Formats\TrigParser;
use FancyRDF\Formats\TrigReader\TrigReader;
use FancyRDF\Streaming\ResourceStreamReader;
use FancyRDF\Term\BlankNode;
use FancyRDF\Term\Iri;
use FancyRDF\Term\Literal;
use FancyRDF\Uri\UriReference;
use Generator;
use RuntimeException;

use function dirname;
use function explode;
use function fclose;
use function file_exists;
use function fopen;
use function implode;
use function ksort;
use function strlen;
use function strpos;
use function substr;

use const DIRECTORY_SEPARATOR;

/**
 * Class for loading a local copy of the W3C RDF Test Suite.
 *
 * @see https://www.w3.org/TR/rdf11-testcases/
 */
class Rdf11TestCases
{
    /** @var array<string, array<string, list<Iri|Literal|BlankNode>>> */
    private array $manifestCache = [];

    private string $baseIri;
    private string $baseDirectory;

    public function __construct(
        private readonly string $manifestPath,
        private readonly string $manifestIri,
    ) {
        $this->baseDirectory = dirname($this->manifestPath) . DIRECTORY_SEPARATOR;
        $this->baseIri       = UriReference::resolveRelative($manifestIri, '.');

        $turtleStream = fopen($this->manifestPath, 'r');
        if ($turtleStream === false) {
            throw new RuntimeException('failed to open manifest turtle stream at ' . $this->manifestPath);
        }

        try {
            $stream = new ResourceStreamReader($turtleStream);
            $reader = new TrigReader($stream);
            $parser = new TrigParser($reader, false, $manifestIri);

            $cache = [];
            foreach ($parser as [$s, $p, $o]) {
                $sKey = $s instanceof BlankNode ? $s->label : $s->iri;
                $pKey = $p->iri;

                if (! isset($cache[$sKey])) {
                    $cache[$sKey] = [];
                }

                if (! isset($cache[$sKey][$pKey])) {
                    $cache[$sKey][$pKey] = [];
                }

                $cache[$sKey][$pKey][] = $o;
            }
        } finally {
            fclose($turtleStream);
        }

        $this->manifestCache = $cache;
    }

    public const string MF_ENTRIES_IRI = 'http://www.w3.org/2001/sw/DataAccess/tests/test-manifest#entries';
    public const string RDF_TYPE_IRI   = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type';
    public const string LIST_FIRST_IRI = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#first';
    public const string LIST_REST_IRI  = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#rest';
    public const string LIST_NIL_IRI   = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#nil';

    /**
     * Returns a list of all entry IRIs from the manifest.
     *
     * @return Generator<int, Iri, mixed, void>
     */
    private function getAllEntries(): Generator
    {
        $entries  = $this->manifestCache[$this->manifestIri][self::MF_ENTRIES_IRI] ?? [];
        $listNode = $entries[0] ?? new Iri(self::LIST_NIL_IRI);

        while ($listNode instanceof BlankNode || ($listNode instanceof Iri && $listNode->iri !== self::LIST_NIL_IRI)) {
            $subjectKey  = $listNode instanceof BlankNode ? $listNode->label : $listNode->iri;
            $byPredicate = $this->manifestCache[$subjectKey] ?? [];

            $firstObjs = $byPredicate[self::LIST_FIRST_IRI] ?? [];
            if (isset($firstObjs[0]) && $firstObjs[0] instanceof Iri) {
                yield $firstObjs[0];
            }

            $restObjs = $byPredicate[self::LIST_REST_IRI] ?? [];
            $listNode = $restObjs[0] ?? new Iri(self::LIST_NIL_IRI);
        }
    }

    /**
     * Loads a list of entries from the manifest.
     *
     * @param Iri $typ
     *   The IRI of the type of entries to load.
     *
     * @return Generator<int, array{iri: string, name: string, comment: string|null, action: string, result: string|null}, mixed, void>
     */
    public function loadEntries(Iri $typ): Generator
    {
        foreach ($this->getAllEntries() as $entry) {
            $type = $this->getType($entry);
            if ($type === null || ! $type->equals($typ)) {
                continue;
            }

            $info = $this->getEntry($entry);
            if ($info === null) {
                continue;
            }

            yield $info;
        }
    }

    /**
     * Counts the types of entries found in the manifest.
     *
     * @return array<string, int>
     *   The number of entries for each type.
     *   The array is sorted by key.
     */
    public function countEntryTypes(): array
    {
        $types = [];
        foreach ($this->getAllEntries() as $entry) {
            $type = $this->getType($entry);
            if ($type === null) {
                continue;
            }

            $types[$type->iri] = ($types[$type->iri] ?? 0) + 1;
        }

        ksort($types);

        return $types;
    }

    public const string TEST_MANIFEST_NAME_IRI   = 'http://www.w3.org/2001/sw/DataAccess/tests/test-manifest#name';
    public const string RDFS_COMMENT_IRI         = 'http://www.w3.org/2000/01/rdf-schema#comment';
    public const string TEST_MANIFEST_ACTION_IRI = 'http://www.w3.org/2001/sw/DataAccess/tests/test-manifest#action';
    public const string TEST_MANIFEST_RESULT_IRI = 'http://www.w3.org/2001/sw/DataAccess/tests/test-manifest#result';

    /**
     * Gets an entry from the manifest.
     *
     * @return array{iri: string, name: string, comment: string|null, action: string, result: string|null}|null
     */
    public function getEntry(Iri $iri): array|null
    {
        $name    = $this->getFirstProperty($iri, new Iri(self::TEST_MANIFEST_NAME_IRI));
        $comment = $this->getFirstProperty($iri, new Iri(self::RDFS_COMMENT_IRI));
        $action  = $this->getFirstProperty($iri, new Iri(self::TEST_MANIFEST_ACTION_IRI));
        $result  = $this->getFirstProperty($iri, new Iri(self::TEST_MANIFEST_RESULT_IRI));

        $nameStr    = $name instanceof Literal ? $name->lexical : null;
        $commentStr = $comment instanceof Literal ? $comment->lexical : null;
        $actionStr  = $action instanceof Iri ? $action->iri : null;
        $resultStr  = $result instanceof Iri ? $result->iri : null;

        if ($nameStr === null || $actionStr === null) {
            return null;
        }

        return [
            'iri' => $iri->iri,
            'name' => $nameStr,
            'comment' => $commentStr,
            'action' => $actionStr,
            'result' => $resultStr,
        ];
    }

    /**
     * Resolves an IRI into a local file path and returns it.
     *
     * If the URI does not start with the action base URI, null is returned.
     *
     * @throws RuntimeException if the IRI is within the base IRI but the file does not exist.
     */
    public function resolve(string $iri): string|null
    {
        $index = strpos($iri, $this->baseIri);
        if ($index === false) {
            return null;
        }

        $components = explode('/', substr($iri, $index + strlen($this->baseIri)));
        $path       = $this->baseDirectory . implode('/', $components);
        if (! file_exists($path)) {
            throw new RuntimeException('File at ' . $path . ' does not exist (resolved from ' . $iri . ' with base IRI ' . $this->baseIri . ' and base directory ' . $this->baseDirectory . ')');
        }

        return $path;
    }

    /**
     * Gets a list of property => value mappings for a specific element.
     *
     * @return Generator<string, list<Iri|Literal|BlankNode>>
     */
    public function getProperties(Iri $element): Generator
    {
        yield from $this->manifestCache[$element->iri] ?? [];
    }

    /**
     * Gets the first property of an element.
     *
     * @param Iri $subject
     *   The IRI of the subject to get the property for.
     * @param Iri $property
     *   The IRI of the property to get the value for.
     */
    public function getFirstProperty(Iri $subject, Iri $property): Iri|Literal|BlankNode|null
    {
        $properties = $this->manifestCache[$subject->iri][$property->iri] ?? [];

        return $properties[0] ?? null;
    }

    /**
     * Gets the first type of an element.
     *
     * @param Iri $element
     *   The IRI of the element to get the type for.
     *
     * @return Iri|null
     *   The first type of the element, or null if no type is found.
     */
    public function getType(Iri $element): Iri|null
    {
        $typ = $this->getFirstProperty($element, new Iri(self::RDF_TYPE_IRI));

        return $typ instanceof Iri ? $typ : null;
    }
}
