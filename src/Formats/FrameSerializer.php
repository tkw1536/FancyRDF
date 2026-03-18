<?php

declare(strict_types=1);

namespace FancyRDF\Formats;

use FancyRDF\Dataset\Quad;
use FancyRDF\Term\BlankNode;
use FancyRDF\Term\Iri;
use FancyRDF\Term\Literal;
use InvalidArgumentException;
use RuntimeException;

use function array_key_exists;
use function array_pop;
use function count;
use function strlen;
use function strrpos;
use function substr;

/**
 * Base class for serializers that maintain a logical frame stack (root/graph/subject/property)
 * and namespace/prefix mappings while delegating concrete output to subclasses.
 *
 * @internal
 *
 * @phpstan-import-type TripleOrQuadArray from Quad
 * @phpstan-sealed RdfXmlSerializer
 */
abstract class FrameSerializer
{
    private const string FRAME_ROOT     = 'root';
    private const string FRAME_GRAPH    = 'graph';
    private const string FRAME_SUBJECT  = 'subject';
    private const string FRAME_PROPERTY = 'property';

    /**
     * @var list<
     *   array{type: 'root'}
     *   |array{type: 'graph', graph: Iri|BlankNode}
     *   |array{type: 'subject', subject: Iri|BlankNode}
     *   |array{type: 'property', predicate: Iri}
     * >
     */
    private array $frameStack = [];

    /** @var array<string, string> prefix => namespace URI */
    private array $prefixToNamespace = [];

    /** @var array<string, non-empty-string> namespace URI => prefix */
    private array $namespaceToPrefix = [];

    /** @var array<string, bool> namespace URIs that are declared on the root element */
    private array $rootDeclaredNamespaces = [];

    /** @var array<string, bool> namespace URIs that still require a local declaration */
    private array $needsLocalDeclaration = [];

    private int $nextAutoPrefixId = 1;

    private bool $started = false;

    private bool $closed = false;

    private Iri|BlankNode|null $currentGraph = null;

    /**
     * @param array<string, non-empty-string> $prefixes
     *   A mapping of prefixes to namespace URIs that should be declared on the root element.
     *
     * @throws InvalidArgumentException
     */
    public function __construct(array $prefixes = [])
    {
        foreach ($prefixes as $prefix => $namespace) {
            if ($prefix === '') {
                continue;
            }

            if (isset($this->prefixToNamespace[$prefix]) && $this->prefixToNamespace[$prefix] !== $namespace) {
                throw new InvalidArgumentException('Prefix "' . $prefix . '" already mapped to a different namespace');
            }

            if (isset($this->namespaceToPrefix[$namespace]) && $this->namespaceToPrefix[$namespace] !== $prefix) {
                throw new InvalidArgumentException('Namespace "' . $namespace . '" already mapped to a different prefix');
            }

            $this->prefixToNamespace[$prefix]         = $namespace;
            $this->namespaceToPrefix[$namespace]      = $prefix;
            $this->rootDeclaredNamespaces[$namespace] = true;
        }
    }

    /**
     * Writes a single triple or quad to this serializer.
     *
     * It is not guaranteed that the entire quad is immediately written to the output.
     * To finish writing any pending quads, call {@see close()}.
     *
     * @param TripleOrQuadArray $quad
     *
     * @throws RuntimeException if close has been called before calling write.
     * @throws InvalidArgumentException
     */
    final public function write(array $quad): void
    {
        if ($this->closed) {
            throw new RuntimeException('Cannot write after serializer has been closed');
        }

        $this->ensureStarted();

        [$subject, $predicate, $object, $graph] = $quad;

        // Graphs are treated as top-level, non-nestable frames directly under the root.
        // At most one graph frame is open at a time.
        $graphChanged = false;
        if ($graph === null && $this->currentGraph !== null) {
            $graphChanged = true;
        } elseif ($graph !== null && $this->currentGraph === null) {
            $graphChanged = true;
        } elseif ($graph !== null && $this->currentGraph !== null && ! $this->currentGraph->equals($graph, true)) {
            $graphChanged = true;
        }

        if ($graphChanged) {
            // Close any open subject/property (and previous graph, if any) down to the root.
            $this->closeUntilRoot();

            $this->currentGraph = $graph;
            if ($graph !== null) {
                $this->openGraphFrame($graph);
            }
        }

        $subjectIndex = $this->findSubjectFrameIndex($subject);
        if ($subjectIndex === null) {
            // No reusable subject in the current graph (or default graph) – close down
            // to the current graph frame (or root) and open a new subject frame.
            $graphIndex = $this->currentGraph === null ? 0 : 1; // [root] or [root, graph]
            $this->closeFramesAbove($graphIndex);
            $this->openSubjectFrame($subject);
        } else {
            $this->closeFramesAbove($subjectIndex);
        }

        $this->writePredicateAndObject($predicate, $object);
    }

    /**
     * Closes any open frames (graph, subject, property) down to the root,
     * so that pending output is written. The document remains open for further writes.
     * Does nothing if the serializer has not been started or has already been closed.
     *
     * @throws InvalidArgumentException
     */
    final public function flush(): void
    {
        if ($this->closed || ! $this->started) {
            return;
        }

        $this->closeUntilRoot();
        $this->currentGraph = null;
    }

    /**
     * Flushes any pending quads to the output and prevents further write.
     * Calling close multiple times has no effect.
     *
     * @throws InvalidArgumentException
     */
    final public function close(): void
    {
        if ($this->closed) {
            return;
        }

        if (! $this->started) {
            $this->ensureStarted();
        }

        $this->flush();

        $this->closed = true;

        $this->closeTopFrame();

        $this->doEndDocument();
    }

    // ==============================
    // Abstract hooks for subclasses
    // ==============================

    /** Starts the document */
    abstract protected function doStartDocument(): void;

    /** Ends the document */
    abstract protected function doEndDocument(): void;

    /**
     * Opens the root with a set of namespaces.
     *
     * @param array<string, non-empty-string> $namespaces namespace URI => prefix
     */
    abstract protected function doOpenRoot(array $namespaces): void;

    /** Closes the root element. */
    abstract protected function doCloseRoot(): void;

    /**
     * Opens a graph frame for the given graph name.
     *
     * Implementations should start any structure needed to represent a named graph.
     *
     * @throws InvalidArgumentException
     */
    abstract protected function doOpenGraph(Iri|BlankNode $graph): void;

    /**
     * Closes the graph frame for the given graph name.
     *
     * Implementations should finish any structure started in {@see doOpenGraph()}.
     *
     * @throws InvalidArgumentException
     */
    abstract protected function doCloseGraph(Iri|BlankNode $graph): void;

    /**
     * Opens a subject frame for the given subject.
     *
     * Implementations should begin the representation of the subject resource.
     */
    abstract protected function doOpenSubject(Iri|BlankNode $subject): void;

    /**
     * Closes the subject frame for the given subject.
     *
     * Implementations should finish any structure started in {@see doOpenSubject()}.
     */
    abstract protected function doCloseSubject(Iri|BlankNode $subject): void;

    /**
     * Opens a property frame for the given predicate.
     *
     * @param non-empty-string $namespace
     * @param non-empty-string $localName
     * @param non-empty-string $prefix
     */
    abstract protected function doOpenProperty(Iri $predicate, string $namespace, string $localName, string $prefix): void;

    /**
     * Closes the property frame for the given predicate.
     *
     * Implementations should finish any structure started in {@see doOpenProperty()}.
     */
    abstract protected function doCloseProperty(Iri $predicate): void;

    /**
     * Writes a literal value in the current property frame.
     *
     * Implementations should serialize the literal according to the target format.
     */
    abstract protected function doLiteral(Literal $literal): void;

    // ===========================
    // Final helpers for children
    // ===========================

    /**
     * Ensures that there is a prefix for the given namespace URI and returns it.
     *
     * @param non-empty-string $namespace
     *
     * @return non-empty-string
     */
    final protected function ensurePrefixForNamespace(string $namespace): string
    {
        if (isset($this->namespaceToPrefix[$namespace])) {
            return $this->namespaceToPrefix[$namespace];
        }

        do {
            $prefix = 'ns' . $this->nextAutoPrefixId;
            $this->nextAutoPrefixId++;
        } while (isset($this->prefixToNamespace[$prefix]));

        $this->prefixToNamespace[$prefix]        = $namespace;
        $this->namespaceToPrefix[$namespace]     = $prefix;
        $this->needsLocalDeclaration[$namespace] = true;

        return $prefix;
    }

    /**
     * @param non-empty-string $iri
     *
     * @return array{0: non-empty-string, 1: non-empty-string} [namespace, localName]
     *
     * @throws RuntimeException
     */
    final protected function splitNamespaceAndLocalName(string $iri): array
    {
        $pos = strrpos($iri, '#');
        if ($pos === false) {
            $pos = strrpos($iri, '/');
        }

        if ($pos === false || $pos === strlen($iri) - 1) {
            throw new RuntimeException('Cannot split IRI into namespace and local name: ' . $iri);
        }

        $namespace = substr($iri, 0, $pos + 1);
        $localName = substr($iri, $pos + 1);
        if ($namespace === '' || $localName === '') {
            throw new RuntimeException('Cannot split IRI into namespace and local name: ' . $iri);
        }

        return [$namespace, $localName];
    }

    /**
     * Returns true if the given namespace still requires a local declaration.
     */
    final protected function namespaceNeedsLocalDeclaration(string $namespace): bool
    {
        return array_key_exists($namespace, $this->needsLocalDeclaration);
    }

    /**
     * Marks the given namespace as having been declared locally.
     */
    final protected function markNamespaceDeclaredLocally(string $namespace): void
    {
        unset($this->needsLocalDeclaration[$namespace]);
    }

    /** @return array<string, non-empty-string> namespace URI => prefix */
    final protected function getRootNamespaces(): array
    {
        $result = [];
        foreach ($this->rootDeclaredNamespaces as $namespace => $unused) {
            $prefix             = $this->namespaceToPrefix[$namespace];
            $result[$namespace] = $prefix;
        }

        return $result;
    }

    // ====================
    // Internal stack logic
    // ====================

    /** Ensures that the serializer has been started and the root frame has been opened. */
    private function ensureStarted(): void
    {
        if ($this->started) {
            return;
        }

        $this->started = true;

        $this->doStartDocument();
        $this->doOpenRoot($this->getRootNamespaces());

        $this->frameStack[] = ['type' => self::FRAME_ROOT];
    }

    private function findSubjectFrameIndex(Iri|BlankNode $subject): int|null
    {
        for ($i = count($this->frameStack) - 1; $i >= 0; $i--) {
            $frame = $this->frameStack[$i];
            if ($frame['type'] !== self::FRAME_SUBJECT) {
                continue;
            }

            $candidate = $frame['subject'];
            if ($candidate->equals($subject, true)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Closes all frames above the given index, leaving the frame at $index open.
     *
     * @throws InvalidArgumentException
     */
    private function closeFramesAbove(int $index): void
    {
        for ($i = count($this->frameStack) - 1; $i > $index; $i--) {
            $this->closeTopFrame();
        }
    }

    /**
     * Closes all frames down to the root frame, but not including it.
     *
     * @throws InvalidArgumentException
     */
    private function closeUntilRoot(): void
    {
        for ($i = count($this->frameStack) - 1; $i >= 0; $i--) {
            $frame = $this->frameStack[$i];
            if ($frame['type'] === self::FRAME_ROOT) {
                break;
            }

            $this->closeTopFrame();
        }
    }

    /** @throws InvalidArgumentException */
    private function openGraphFrame(Iri|BlankNode $graph): void
    {
        $this->doOpenGraph($graph);
        $this->frameStack[] = [
            'type' => self::FRAME_GRAPH,
            'graph' => $graph,
        ];
    }

    private function openSubjectFrame(Iri|BlankNode $subject): void
    {
        $this->doOpenSubject($subject);
        $this->frameStack[] = [
            'type' => self::FRAME_SUBJECT,
            'subject' => $subject,
        ];
    }

    private function openPropertyFrame(Iri $predicate): void
    {
        $this->frameStack[] = [
            'type' => self::FRAME_PROPERTY,
            'predicate' => $predicate,
        ];
    }

    /** @throws InvalidArgumentException */
    private function closeTopFrame(): void
    {
        $frame = array_pop($this->frameStack);
        if ($frame === null) {
            return;
        }

        switch ($frame['type']) {
            case self::FRAME_PROPERTY:
                $this->doCloseProperty($frame['predicate']);
                break;
            case self::FRAME_SUBJECT:
                $this->doCloseSubject($frame['subject']);
                break;
            case self::FRAME_GRAPH:
                $this->doCloseGraph($frame['graph']);
                break;
            case self::FRAME_ROOT:
                $this->doCloseRoot();
                break;
        }
    }

    /**
     * @throws RuntimeException
     * @throws InvalidArgumentException
     */
    private function writePredicateAndObject(Iri $predicate, Iri|Literal|BlankNode $object): void
    {
        [$namespace, $localName] = $this->splitNamespaceAndLocalName($predicate->iri);
        $prefix                  = $this->ensurePrefixForNamespace($namespace);

        $this->doOpenProperty($predicate, $namespace, $localName, $prefix);
        $this->openPropertyFrame($predicate);

        if ($object instanceof Literal) {
            $this->doLiteral($object);

            $this->closeTopFrame();

            return;
        }

        $this->openSubjectFrame($object);
    }
}
