<?php

declare(strict_types=1);

namespace FancyRDF\Dataset\RdfCanon;

use FancyRDF\Dataset\Dataset;
use FancyRDF\Dataset\Quad;
use FancyRDF\Exceptions\CanonicalizationLimitExceeded;
use FancyRDF\Formats\NFormatSerializer;
use FancyRDF\Term\BlankNode;
use Generator;
use RuntimeException;

use function assert;
use function count;
use function hash;
use function hash_algos;
use function hrtime;
use function implode;
use function in_array;
use function iterator_to_array;
use function ksort;
use function strcmp;
use function strlen;
use function usort;

/**
 * Implements RDF Dataset Canonicalization Algorithm (RDFC-1.0).
 *
 * TODO: Do we want to work this into the NFormatSerializer?
 *
 * @see https://www.w3.org/TR/rdf-canon/
 *
 * @phpstan-import-type TripleOrQuadArray from Quad
 */
final class RdfCanonicalizer
{
    public function __construct(RdfCanonicalizationOptions|null $options = null)
    {
        $this->options = $options ?? new RdfCanonicalizationOptions();

        if (! in_array($this->options->hashAlgorithm, hash_algos(), true)) {
            throw new RuntimeException('Unsupported hash algorithm: ' . $this->options->hashAlgorithm);
        }

        $this->reset();
    }

    /**
     * Options for the canonicalization process.
     *
     * @var RdfCanonicalizationOptions
     */
    private readonly RdfCanonicalizationOptions $options;

    /**
     * The start time of the canonicalization process.
     *
     * Used to enforce a time lint on the canonicalization process.
     */
    private int $startTimeNs;

    /**
     * The number of permutations explored during the canonicalization process.
     *
     * Used to enforce a limit on the number of permutations explored during the canonicalization process.
     */
    private int $permutationsExplored;

    /**
     * A map that relates a blank node identifier to the quads in which they appear in the input dataset.
     *
     * @see https://www.w3.org/TR/rdf-canon/#dfn-blank-node-to-quads-map
     *
     * @var array<non-empty-string, list<TripleOrQuadArray>>
     * */
    private array $blankNodeToQuads;

    /**
     * A map that relates a hash to a list of blank node identifiers.
     *
     * @see https://www.w3.org/TR/rdf-canon/#dfn-hash-to-blank-nodes-map
     *
     * @var array<non-empty-string, list<non-empty-string>>
     */
    private array $hashToBlankNodes;

    /**
     * An identifier issuer that is used to issue new blank node identifiers.
     *
     * @see https://www.w3.org/TR/rdf-canon/#dfn-canonical-issuer
     */
    private IdentifierIssuer $canonicalIssuer;

    private function reset(): void
    {
        $this->startTimeNs          = (int) hrtime(true);
        $this->permutationsExplored = 0;

        $this->blankNodeToQuads     = [];
        $this->firstDegreeHashCache = [];
        $this->hashToBlankNodes     = [];
        $this->canonicalIssuer      = new IdentifierIssuer('c14n');
    }

    /**
     * Canonicalizes the input dataset, implementing the RDFC-1.0 canonicalization algorithm.
     *
     * @see https://www.w3.org/TR/rdf-canon/#canon-algo-algo
     */
    public function canonicalize(Dataset $input): RdfCanonicalizationResult
    {
        // 1) Create the canonicalization state. If the input dataset is an N-Quads document, parse that document into a dataset in the canonicalized dataset, retaining any blank node identifiers used within that document in the input blank node identifier map; otherwise arbitrary identifiers are assigned for each blank node.
        $this->reset();

        /** @var list<TripleOrQuadArray> $inputDataset */
        $inputDataset = iterator_to_array($input->unique(true));

        // 2) For every quad Q in input dataset:
        foreach ($inputDataset as $q) {
            $this->checkTime('build blank node to quads map');

            // For each blank node that is a component of Q, add a reference to Q from the map entry for the blank node identifier identifier in the blank node to quads map, creating a new entry if necessary, using the identifier for the blank node found in the input blank node identifier map.
            foreach ([$q[0], $q[2], $q[3]] as $component) {
                if (! $component instanceof BlankNode) {
                    continue;
                }

                $id = $component->identifier;

                $this->blankNodeToQuads[$id] ??= [];
                $this->blankNodeToQuads[$id][] = $q;
            }
        }

        // 3) For each key n in the blank node to quads map:
        foreach ($this->blankNodeToQuads as $n => $_map) {
            // 3.1) Create a hash, hf(n), for n according to the Hash First Degree Quads algorithm.
            $hash = $this->hashFirstDegreeQuads($n);

            // 3.2) Append n to the value associated to hf(n) in hash to blank nodes map, creating a new entry if necessary.
            $this->hashToBlankNodes[$hash] ??= [];
            $this->hashToBlankNodes[$hash][] = $n;
        }

        // 4) For each hash to identifier list map entry in hash to blank nodes map, code point ordered by hash:
        ksort($this->hashToBlankNodes);
        foreach ($this->hashToBlankNodes as $hash => $bnList) {
            $this->checkTime('label unique nodes');

            // 4.1) If identifier list has more than one entry, continue to the next mapping.
            if (count($bnList) > 1) {
                continue;
            }

            // 4.2) Use the Issue Identifier algorithm, passing canonical issuer and the single blank node identifier, identifier in identifier list to issue a canonical replacement identifier for identifier.
            $this->canonicalIssuer->issue($bnList[0]);

            // 4.3) Remove the map entry for hash from the hash to blank nodes map.
            unset($this->hashToBlankNodes[$hash]);
        }

        // 5) For each hash to identifier list map entry in hash to blank nodes map, code point ordered by hash:
        // ksort($this->hashToBlankNodes); // don't need to re-sort, because we already sorted above!
        foreach ($this->hashToBlankNodes as $hash => $identifierList) {
            $this->checkTime('process repeated first-degree hash group');

            // 5.1) Create hash path list where each item will be a result of running the Hash N-Degree Quads algorithm.
            $hashPathList = [];
            // 5.2) For each blank node identifier n in identifier list:
            foreach ($identifierList as $n) {
                // 5.2.1) If a canonical identifier has already been issued for n, continue to the next blank node identifier.
                if ($this->canonicalIssuer->has($n)) {
                    continue;
                }

                // 5.2.2) Create temporary issuer, an identifier issuer initialized with the prefix b.
                $issuer = new IdentifierIssuer('b');
                // 5.2.3) Use the Issue Identifier algorithm, passing temporary issuer and n, to issue a new temporary blank node identifier bn to n.
                $issuer->issue($n);

                // 5.2.4) Run the Hash N-Degree Quads algorithm, passing the canonicalization state, n for identifier, and temporary issuer, appending the result to the hash path list.
                $result         = $this->hashNDegreeQuads($n, $issuer, 0);
                $hashPathList[] = $result;
            }

            // 5.3) For each result in the hash path list, code point ordered by the hash in result:
            usort(
                $hashPathList,
                static fn (array $a, array $b): int => strcmp($a['hash'], $b['hash']),
            );

            foreach ($hashPathList as $result) {
                // 5.3.1) For each blank node identifier, existing identifier, that was issued a temporary identifier by identifier issuer in result, issue a canonical identifier, in the same order, using the Issue Identifier algorithm, passing canonical issuer and existing identifier.
                $issuer = $result['issuer'];
                foreach ($issuer as $existingIdentifier => $_issuedIdentifier) {
                    $this->canonicalIssuer->issue($existingIdentifier);
                }
            }
        }

        // 6) Add the issued identifiers map from the canonical issuer to the canonicalized dataset.
        $blankNodeMap = iterator_to_array($this->canonicalIssuer);

        $canonicalized = [];
        foreach ($inputDataset as $quad) {
            $canonicalized[] = Quad::rename($quad, static fn (string $id): string => $blankNodeMap[$id]);
        }

        $canonicalized = new Dataset($canonicalized);

        // 7) Return the serialized canonical form of the canonicalized dataset. Upon request, alternatively (or additionally) return the canonicalized dataset itself, which includes the input blank node identifier map, and issued identifiers map from the canonical issuer.
        return new RdfCanonicalizationResult(new Dataset($canonicalized), $blankNodeMap);
    }

    /**
     * A cache of first degree hashes, mapping each blank node identifier to its first degree hash.
     *
     * We can cache this as the hash only depends on the blankNodeToQuads map, which never changes during the algorithm.
     *
     * @var array<non-empty-string, non-empty-string>
     */
    private array $firstDegreeHashCache = [];

    /**
     * Implements RDFC-1.0 §4.6 Hash First Degree Quads.
     *
     * The result itself only depends on the blank node identifier and the quads in which it appears in the input dataset,
     * so we additionally cache the result.
     *
     * @see https://www.w3.org/TR/rdf-canon/#hash-1d-quads-algorithm
     *
     * @param non-empty-string $reference
     *
     * @return non-empty-string
     */
    private function hashFirstDegreeQuads(string $reference): string
    {
        // Check if we have the result cached, and if so return it.
        if (isset($this->firstDegreeHashCache[$reference])) {
            return $this->firstDegreeHashCache[$reference];
        }

        $this->checkTime('hash first degree quads');

        // 1) Initialize nquads to an empty list. It will be used to store quads in canonical n-quads form.
        $nquads = [];

        // 2) Get the list of quads quads from the map entry for reference blank node identifier in the blank node to quads map.
        $quads = $this->blankNodeToQuads[$reference] ?? [];

        // 3) For each quad quad in quads:
        // Serialize the quad in canonical n-quads form with the following special rule:
        // If any component in quad is an blank node, then serialize it using a special identifier as follows:
        // If the blank node's existing blank node identifier matches the reference blank node identifier then use the blank node identifier a, otherwise, use the blank node identifier z.
        $mapper = static function (string $id) use ($reference): string {
            return $id === $reference ? 'a' : 'z';
        };

        foreach ($quads as $quad) {
            $nquads[] = NFormatSerializer::serialize(Quad::rename($quad, $mapper));
        }

        // 4) Sort nquads in Unicode code point order.
        usort($nquads, 'strcmp');

        // 5) Compute the hash that results from passing the sorted and concatenated nquads through the hash algorithm.
        $hash = $this->hashString(implode('', $nquads));

        // Cache the result and return it
        $this->firstDegreeHashCache[$reference] = $hash;

        return $hash;
    }

    /**
     * RDFC-1.0 §4.7 Hash Related Blank Node.
     *
     * @see https://www.w3.org/TR/rdf-canon/#hash-related-blank-node
     * @see https://www.w3.org/TR/rdf-canon/#hash-related-algorithm
     *
     * @param TripleOrQuadArray $quad
     * @param non-empty-string  $related
     * @param 's'|'o'|'g'       $position
     *
     * @return non-empty-string
     */
    private function hashRelatedBlankNode(string $related, array $quad, IdentifierIssuer $issuer, string $position): string
    {
        $this->checkTime('hash related blank node');

        // 1) Initialize a string input to the value of position.
        $input = $position;

        // 2) If position is not g, append <, the value of the predicate in quad, and > to input.
        if ($position !== 'g') {
            $input .= $quad[1]->__toString();
        }

        // 3) If there is a canonical identifier for related, or an identifier issued by issuer, append the string _:, followed by that identifier (using the canonical identifier if present, otherwise the one issued by issuer) to input.
        $identifier = $this->canonicalIssuer->get($related) ?? $issuer->get($related);
        if ($identifier !== null) {
            $input .= '_:' . $identifier;
        } else {
            // 4) Otherwise, append the result of the Hash First Degree Quads algorithm, passing related to input.
            $input .= $this->hashFirstDegreeQuads($related);
        }

        // 5) Return the hash that results from passing input through the hash algorithm.
        return $this->hashString($input);
    }

    private int $nDegreeQuadCalls = 0;

    /**
     * RDFC-1.0 §4.8 Hash N-Degree Quads.
     *
     * @see https://www.w3.org/TR/rdf-canon/#hash-nd-quads-algorithm
     *
     * @param non-empty-string $identifier
     *
     * @return array{hash: non-empty-string, issuer: IdentifierIssuer}
     */
    private function hashNDegreeQuads(string $identifier, IdentifierIssuer $issuer, int $depth): array
    {
        $this->checkTime('hash n-degree quads');

        if ($this->options->maxHashNDegreeDepth !== null && $depth >= $this->options->maxHashNDegreeDepth) {
            throw new CanonicalizationLimitExceeded(
                'maxRecursionDepth',
                'HashN-DegreeQuads(' . $identifier . ')',
                'Maximum recursion depth exceeded',
            );
        }

        $this->nDegreeQuadCalls++;
        if ($this->options->maxHashNDegreeQuadCalls !== null && $this->nDegreeQuadCalls >= $this->options->maxHashNDegreeQuadCalls) {
            throw new CanonicalizationLimitExceeded(
                'maxNDegreeQuadCalls',
                'HashN-DegreeQuads(' . $identifier . ')',
                'Maximum N-Degree Quads calls exceeded',
            );
        }

        // 1) Create a new map Hn for relating hashes to related blank nodes.

        /** @var array<non-empty-string, list<non-empty-string>> $hashNSets */
        $hashNSets = [];

        // 2) Get a reference, quads, to the list of quads from the map entry for identifier in the blank node to quads map.
        $quads = $this->blankNodeToQuads[$identifier] ?? [];

        // 3) For each quad in quads:
        foreach ($quads as $quad) {
            $this->checkTime('hash n-degree quads: build Hn');
            // For each component in quad, where component is the subject, object, or graph name, and it is a blank node that is not identified by identifier:

            foreach (
                [
                    's' => $quad[0],
                    'o' => $quad[2],
                    'g' => $quad[3],
                ] as $position => $component
            ) {
                if (! $component instanceof BlankNode || $component->identifier === $identifier) {
                    continue;
                }

                // Set hash to the result of the Hash Related Blank Node algorithm, passing the blank node identifier for component as related, quad, issuer, and position as either s, o, or g based on whether component is a subject, object, graph name, respectively.
                $related = $component->identifier;
                $hash    = $this->hashRelatedBlankNode($related, $quad, $issuer, $position);

                // Add a mapping of hash to the blank node identifier for component to Hn, adding an entry as necessary.
                $hashNSets[$hash] ??= [];
                $hashNSets[$hash][] = $related;
            }
        }

        // 4) Create an empty string, data to hash.
        $dataToHash = '';

        // 5) For each related hash to blank node list mapping in Hn, code point ordered by related hash:
        ksort($hashNSets);
        foreach ($hashNSets as $relatedHash => $blankNodeList) {
            $this->checkTime('hash n-degree quads: explore permutations');

            // 5.1) Append the related hash to the data to hash.
            $dataToHash .= $relatedHash;

            // 5.2) Create a string chosen path.
            $chosenPath = '';
            // 5.3) Create an unset chosen issuer variable.
            $chosenIssuer = null;

            // 5.4) For each permutation p of blank node list:
            foreach ($this->permutations($blankNodeList) as $p) {
                $this->permutationsExplored++;
                if ($this->options->maxPermutations !== null && $this->permutationsExplored > $this->options->maxPermutations) {
                    throw new CanonicalizationLimitExceeded(
                        'maxPermutations',
                        'HashN-DegreeQuads(' . $identifier . ')',
                        'Maximum permutations explored exceeded',
                    );
                }

                // 5.4.1) Create a copy of issuer, issuer copy.
                $issuerCopy = clone $issuer;
                // 5.4.2) Create a string path.
                $path = '';
                // 5.4.3) Create a recursion list, to store blank node identifiers that must be recursively processed by this algorithm.
                $recursionList = [];

                // 5.4.4) For each related in p:
                foreach ($p as $related) {
                    // 5.4.4.1) If a canonical identifier has been issued for related by canonical issuer, append the string _:, followed by the canonical identifier for related, to path.
                    $canonical = $this->canonicalIssuer->get($related);
                    if ($canonical !== null) {
                        $path .= '_:' . $canonical;
                    } else {
                        // 5.4.4.2) Otherwise:

                        // 5.4.4.2.1) If issuer copy has not issued an identifier for related, append related to recursion list.
                        if (! $issuerCopy->has($related)) {
                            $recursionList[] = $related;
                        }

                        // 5.4.4.2.2) Use the Issue Identifier algorithm, passing issuer copy and the related, and append the string _:, followed by the result, to path.
                        $path .= '_:' . $issuerCopy->issue($related);
                    }

                    // 5.4.4.3) If chosen path is not empty and the length of path is greater than or equal to the length of chosen path and path is greater than chosen path when considering code point order, then skip to the next permutation p.
                    if ($chosenPath !== '' && strlen($path) >= strlen($chosenPath) && strcmp($path, $chosenPath) > 0) {
                        continue 2;
                    }
                }

                // 5.4.5) For each related in recursion list:
                foreach ($recursionList as $related) {
                    // 5.4.5.1) Set result to the result of recursively executing the Hash N-Degree Quads algorithm, passing the canonicalization state, related for identifier, and issuer copy for path identifier issuer.
                    $result     = $this->hashNDegreeQuads($related, $issuerCopy, $depth + 1);
                    $issuerCopy = $result['issuer'];

                    // 5.4.5.2) Use the Issue Identifier algorithm, passing issuer copy and related; append the string _:, followed by the result, to path.
                    $path .= '_:' . $issuerCopy->issue($related);

                    // 5.4.5.3) Append <, the hash in result, and > to path.
                    $path .= '<' . $result['hash'] . '>';

                    // 5.4.5.5) If chosen path is not empty and the length of path is greater than or equal to the length of chosen path and path is greater than chosen path when considering code point order, then skip to the next p.
                    if ($chosenPath !== '' && strlen($path) >= strlen($chosenPath) && strcmp($path, $chosenPath) > 0) {
                        continue 2;
                    }
                }

                // 5.4.6) If chosen path is empty or path is less than chosen path when considering code point order, set chosen path to path and chosen issuer to issuer copy.
                if ($chosenPath !== '' && strcmp($path, $chosenPath) >= 0) {
                    continue;
                }

                $chosenPath   = $path;
                $chosenIssuer = $issuerCopy;
            }

            // 5.5)  Append chosen path to data to hash.
            $dataToHash .= $chosenPath;

            // 5.6) Replace issuer, by reference, with chosen issuer.
            assert($chosenIssuer !== null, 'chosen issuer cannot be null');
            $issuer = $chosenIssuer;
        }

        return [
            'hash' => $this->hashString($dataToHash),
            'issuer' => $issuer,
        ];
    }

    /**
     * Yields each possible permutation of the input items.
     *
     * A permutation of an array is a re-ordering of the elements, with keys and values preserved.
     *
     * @param array<K, T> $items
     *
     * @return Generator<array<K, T>>
     *
     * @template K
     * @template T
     */
    private function permutations(array $items): Generator
    {
        // Base case: If there are 0 or 1 items, we are done!
        $n = count($items);
        if ($n <= 1) {
            yield $items;

            return;
        }

        // Recursive case: Pick which element to use in the last place.
        foreach ($items as $key => $value) {
            // Remove the current element from the list.
            $remaining = $items;
            unset($remaining[$key]);

            // For each permutation of the remaining elements, add the current element to the end.
            foreach ($this->permutations($remaining) as $perm) {
                $perm[$key] = $value;

                yield $perm;
            }
        }
    }

    /** @return non-empty-string */
    private function hashString(string $input): string
    {
        $this->checkTime('hash');

        return hash($this->options->hashAlgorithm, $input);
    }

    private function checkTime(string $context): void
    {
        if ($this->options->maxTimeMs === null) {
            return;
        }

        $elapsedMs = (hrtime(true) - $this->startTimeNs) / 1_000_000;
        if ($elapsedMs > $this->options->maxTimeMs) {
            throw new CanonicalizationLimitExceeded(
                'maxTimeMs',
                $context,
                'Time limit exceeded',
            );
        }
    }
}
