<?php

declare(strict_types=1);

namespace FancyRDF\Dataset\RdfCanon;

use FancyRDF\Dataset\Dataset;
use FancyRDF\Dataset\Quad;
use FancyRDF\Term\BlankNode;
use RuntimeException;

use function array_keys;
use function array_splice;
use function array_unshift;
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
    private readonly RdfCanonicalizationOptions $options;

    private int $permutationsExplored = 0;
    private int $startTimeNs          = 0;

    /** @var array<non-empty-string, list<TripleOrQuadArray>> */
    private array $blankNodeToQuads = [];

    /** @var array<non-empty-string, non-empty-string> */
    private array $firstDegreeHashCache = [];

    private IdentifierIssuer $canonicalIssuer;

    public function __construct(RdfCanonicalizationOptions|null $options = null)
    {
        $this->options = $options ?? new RdfCanonicalizationOptions();

        if (! in_array($this->options->hashAlgorithm, hash_algos(), true)) {
            throw new RuntimeException('Unsupported hash algorithm: ' . $this->options->hashAlgorithm);
        }

        $this->canonicalIssuer = new IdentifierIssuer('c14n');
    }

    public function canonicalize(Dataset $dataset): RdfCanonicalizationResult
    {
        $this->startTimeNs          = (int) hrtime(true);
        $this->permutationsExplored = 0;

        $this->blankNodeToQuads     = [];
        $this->firstDegreeHashCache = [];
        $this->canonicalIssuer      = new IdentifierIssuer('c14n');

        /** @var list<TripleOrQuadArray> $uniqueQuads */
        $uniqueQuads = iterator_to_array($dataset->unique(true));

        $this->buildBlankNodeToQuadsMap($uniqueQuads);
        $blankNodes = array_keys($this->blankNodeToQuads);
        usort($blankNodes, static fn (string $a, string $b): int => strcmp($a, $b));

        // RDFC-1.0 §4.4.3 (3): compute first degree hashes.
        /** @var array<non-empty-string, list<non-empty-string>> $hashToBlankNodes */
        $hashToBlankNodes = [];
        foreach ($blankNodes as $bn) {
            $hash = $this->hashFirstDegreeQuads($bn);

            $hashToBlankNodes[$hash] ??= [];
            $hashToBlankNodes[$hash][] = $bn;
        }

        // RDFC-1.0 §4.4.3 (4): canonically label unique nodes.
        ksort($hashToBlankNodes);
        /** @var array<non-empty-string, list<non-empty-string>> $remaining */
        $remaining = [];
        foreach ($hashToBlankNodes as $hash => $bnList) {
            $this->checkTime('label unique nodes');

            if (count($bnList) === 1) {
                $this->canonicalIssuer->issue($bnList[0]);
            } else {
                $remaining[$hash] = $bnList;
            }
        }

        // RDFC-1.0 §4.4.3 (5): compute N-degree hashes for non-unique nodes.
        foreach ($remaining as $hash => $identifierList) {
            $this->checkTime('process repeated first-degree hash group');

            $hashPathList = [];
            foreach ($identifierList as $n) {
                if ($this->canonicalIssuer->has($n)) {
                    continue;
                }

                $issuer = new IdentifierIssuer('b');
                $issuer->issue($n);

                $result         = $this->hashNDegreeQuads($n, $issuer, 0);
                $hashPathList[] = $result;
            }

            usort(
                $hashPathList,
                static fn (array $a, array $b): int => strcmp($a['hash'], $b['hash']),
            );

            foreach ($hashPathList as $result) {
                $issuer = $result['issuer'];
                foreach ($issuer->getIssuedIdentifiers() as $existingIdentifier => $_issuedIdentifier) {
                    $this->canonicalIssuer->issue($existingIdentifier);
                }
            }
        }

        $blankNodeMap = $this->canonicalIssuer->getIssuedIdentifiers();

        // Rewrite dataset, replacing all blank nodes with their canonical identifiers.
        $rewritten = [];
        foreach ($uniqueQuads as $quad) {
            $rewritten[] = Quad::rename($quad, static fn (string $id): string => $blankNodeMap[$id]);
        }

        return new RdfCanonicalizationResult(new Dataset($rewritten), $blankNodeMap);
    }

    /** @param list<TripleOrQuadArray> $quads */
    private function buildBlankNodeToQuadsMap(array $quads): void
    {
        foreach ($quads as $quad) {
            $this->checkTime('build blank node to quads map');

            foreach ([$quad[0], $quad[2], $quad[3]] as $component) {
                if (! $component instanceof BlankNode) {
                    continue;
                }

                $id = $component->identifier;

                $this->blankNodeToQuads[$id] ??= [];
                $this->blankNodeToQuads[$id][] = $quad;
            }
        }
    }

    /**
     * RDFC-1.0 §4.6 Hash First Degree Quads.
     *
     * @param non-empty-string $reference
     *
     * @return non-empty-string
     */
    private function hashFirstDegreeQuads(string $reference): string
    {
        if (isset($this->firstDegreeHashCache[$reference])) {
            return $this->firstDegreeHashCache[$reference];
        }

        $this->checkTime('hash first degree quads');

        $quads = $this->blankNodeToQuads[$reference] ?? [];

        $nquads = [];
        $mapper = static function (string $id) use ($reference): string {
            return $id === $reference ? 'a' : 'z';
        };

        foreach ($quads as $quad) {
            $nquads[] = CanonicalNQuadsSerializer::serialize($quad, $mapper, true);
        }

        usort($nquads, 'strcmp');
        $hash = $this->hashString(implode('', $nquads));

        $this->firstDegreeHashCache[$reference] = $hash;

        return $hash;
    }

    /**
     * RDFC-1.0 §4.7 Hash Related Blank Node.
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

        $input = $position;
        if ($position !== 'g') {
            $input .= CanonicalNQuadsSerializer::serializeTerm($quad[1]);
        }

        $canonical = $this->canonicalIssuer->get($related);
        if ($canonical !== null) {
            $input .= '_:' . $canonical;
        } else {
            $issued = $issuer->get($related);
            if ($issued !== null) {
                $input .= '_:' . $issued;
            } else {
                $input .= $this->hashFirstDegreeQuads($related);
            }
        }

        return $this->hashString($input);
    }

    private int $nDegreeQuadCalls = 0;

    /**
     * RDFC-1.0 §4.8 Hash N-Degree Quads.
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

        /** @var array<non-empty-string, list<non-empty-string>> $hashNSets */
        $hashNSets = [];

        $quads = $this->blankNodeToQuads[$identifier] ?? [];
        foreach ($quads as $quad) {
            $this->checkTime('hash n-degree quads: build Hn');

            if ($quad[0] instanceof BlankNode && $quad[0]->identifier !== $identifier) {
                $related            = $quad[0]->identifier;
                $hash               = $this->hashRelatedBlankNode($related, $quad, $issuer, 's');
                $hashNSets[$hash] ??= [];
                $hashNSets[$hash][] = $related;
            }

            if ($quad[2] instanceof BlankNode && $quad[2]->identifier !== $identifier) {
                $related            = $quad[2]->identifier;
                $hash               = $this->hashRelatedBlankNode($related, $quad, $issuer, 'o');
                $hashNSets[$hash] ??= [];
                $hashNSets[$hash][] = $related;
            }

            if (! ($quad[3] instanceof BlankNode) || $quad[3]->identifier === $identifier) {
                continue;
            }

            $related            = $quad[3]->identifier;
            $hash               = $this->hashRelatedBlankNode($related, $quad, $issuer, 'g');
            $hashNSets[$hash] ??= [];
            $hashNSets[$hash][] = $related;
        }

        ksort($hashNSets);

        $dataToHash = '';
        foreach ($hashNSets as $relatedHash => $blankNodeList) {
            $this->checkTime('hash n-degree quads: explore permutations');

            $dataToHash .= $relatedHash;

            usort($blankNodeList, static fn (string $a, string $b): int => strcmp($a, $b));

            $chosenPath   = null;
            $chosenIssuer = null;

            foreach ($this->permutations($blankNodeList) as $perm) {
                $this->permutationsExplored++;
                if ($this->options->maxPermutations !== null && $this->permutationsExplored > $this->options->maxPermutations) {
                    throw new CanonicalizationLimitExceeded(
                        'maxPermutations',
                        'HashN-DegreeQuads(' . $identifier . ')',
                        'Maximum permutations explored exceeded',
                    );
                }

                $issuerCopy    = $issuer->copy();
                $path          = '';
                $recursionList = [];

                foreach ($perm as $related) {
                    $canonical = $this->canonicalIssuer->get($related);
                    if ($canonical !== null) {
                        $path .= '_:' . $canonical;
                    } else {
                        if (! $issuerCopy->has($related)) {
                            $recursionList[] = $related;
                        }

                        $path .= '_:' . $issuerCopy->issue($related);
                    }

                    if ($chosenPath !== null && strlen($path) >= strlen($chosenPath) && strcmp($path, $chosenPath) > 0) {
                        continue 2;
                    }
                }

                foreach ($recursionList as $related) {
                    $result     = $this->hashNDegreeQuads($related, $issuerCopy, $depth + 1);
                    $issuerCopy = $result['issuer'];

                    $path .= '_:' . $issuerCopy->issue($related);
                    $path .= '<' . $result['hash'] . '>';

                    if ($chosenPath !== null && strlen($path) >= strlen($chosenPath) && strcmp($path, $chosenPath) > 0) {
                        continue 2;
                    }
                }

                if ($chosenPath !== null && strcmp($path, $chosenPath) >= 0) {
                    continue;
                }

                $chosenPath   = $path;
                $chosenIssuer = $issuerCopy;
            }

            assert($chosenPath !== null, 'no chosen path found');

            $dataToHash .= $chosenPath;
            $issuer      = $chosenIssuer;
        }

        return [
            'hash' => $this->hashString($dataToHash),
            'issuer' => $issuer,
        ];
    }

    /**
     * @param list<non-empty-string> $items
     *
     * @return iterable<list<non-empty-string>>
     */
    private function permutations(array $items): iterable
    {
        if (count($items) === 0) {
            yield [];

            return;
        }

        if (count($items) === 1) {
            yield [$items[0]];

            return;
        }

        foreach ($items as $i => $value) {
            $rest = $items;
            array_splice($rest, $i, 1);
            foreach ($this->permutations($rest) as $perm) {
                array_unshift($perm, $value);

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
