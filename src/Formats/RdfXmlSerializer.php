<?php

declare(strict_types=1);

namespace FancyRDF\Formats;

use FancyRDF\Dataset\Quad;
use FancyRDF\Term\BlankNode;
use FancyRDF\Term\Datatype\XSDString;
use FancyRDF\Term\Iri;
use FancyRDF\Term\Literal;
use FancyRDF\Xml\XMLUtils;
use RuntimeException;
use XMLWriter;

use function array_key_exists;
use function array_pop;
use function count;
use function strlen;
use function strrpos;
use function substr;

/**
 * Serializes triples into readable, nested RDF/XML.
 *
 * Triples are written in streaming fashion. The serializer keeps node elements
 * for subjects open across calls to write(), and tries to reuse the nearest
 * open subject element when possible. This produces nested RDF/XML for
 * resource-valued properties while remaining efficient for large graphs.
 *
 * @phpstan-import-type TripleArray from Quad
 */
final class RdfXmlSerializer
{
    private const string FRAME_ROOT     = 'root';
    private const string FRAME_SUBJECT  = 'subject';
    private const string FRAME_PROPERTY = 'property';

    /** @var list<array{type: 'root'}|array{type: 'subject', subject: Iri|BlankNode}|array{type: 'property'}> */
    private array $frameStack = [];

    /** @var array<string, string> prefix => namespace URI */
    private array $prefixToNamespace = [];

    /** @var array<string, non-empty-string> namespace URI => prefix */
    private array $namespaceToPrefix = [];

    /** @var array<string, bool> namespace URIs that are declared on the root element */
    private array $rootDeclaredNamespaces = [];

    /** @var array<string, bool> namespace URIs that still require a local xmlns declaration */
    private array $needsLocalDeclaration = [];

    private int $nextAutoPrefixId = 1;

    private bool $started = false;

    private bool $closed = false;

    /**
     * @param array<string, non-empty-string> $prefixes
     *   A mapping of XML prefixes to namespace URIs that should be declared on the root element.
     */
    public function __construct(
        private XMLWriter $writer,
        array $prefixes = [],
    ) {
        // Reserve the rdf: prefix for the RDF namespace.
        $rdfNamespace                                = RdfXmlParser::RDF_NAMESPACE;
        $this->prefixToNamespace['rdf']              = $rdfNamespace;
        $this->namespaceToPrefix[$rdfNamespace]      = 'rdf';
        $this->rootDeclaredNamespaces[$rdfNamespace] = true;

        foreach ($prefixes as $prefix => $namespace) {
            if ($prefix === '') {
                // Empty prefixes would create a default namespace, which this serializer does not use.
                continue;
            }

            if ($prefix === 'rdf') {
                if ($namespace !== $rdfNamespace) {
                    throw new RuntimeException('rdf prefix must map to the RDF namespace');
                }

                continue;
            }

            if (isset($this->prefixToNamespace[$prefix]) && $this->prefixToNamespace[$prefix] !== $namespace) {
                throw new RuntimeException('Prefix "' . $prefix . '" already mapped to a different namespace');
            }

            if (isset($this->namespaceToPrefix[$namespace]) && $this->namespaceToPrefix[$namespace] !== $prefix) {
                throw new RuntimeException('Namespace "' . $namespace . '" already mapped to a different prefix');
            }

            $this->prefixToNamespace[$prefix]         = $namespace;
            $this->namespaceToPrefix[$namespace]      = $prefix;
            $this->rootDeclaredNamespaces[$namespace] = true;
        }
    }

    /**
     * Writes a single triple as RDF/XML.
     *
     * @param TripleArray $triple
     */
    public function write(array $triple): void
    {
        if ($this->closed) {
            throw new RuntimeException('Cannot write after serializer has been closed');
        }

        $this->ensureStarted();

        [$subject, $predicate, $object] = $triple;

        $subjectIndex = $this->findSubjectFrameIndex($subject);
        if ($subjectIndex === null) {
            $this->closeUntilRoot();
            $this->openSubjectElement($subject);
        } else {
            $this->closeFramesAbove($subjectIndex);
        }

        $this->writePredicateAndObject($predicate, $object);
    }

    /**
     * Closes any remaining open elements and finishes the document.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        if (! $this->started) {
            $this->ensureStarted();
        }

        while (count($this->frameStack) > 0) {
            $this->writer->endElement();
            array_pop($this->frameStack);
        }

        $this->writer->endDocument();
    }

    private function ensureStarted(): void
    {
        if ($this->started) {
            return;
        }

        $this->started = true;

        $this->writer->setIndent(true);
        $this->writer->setIndentString('  ');

        $this->writer->startDocument('1.0', 'UTF-8');

        $rdfNamespace = RdfXmlParser::RDF_NAMESPACE;
        $this->writer->startElementNs('rdf', 'RDF', $rdfNamespace);

        foreach ($this->rootDeclaredNamespaces as $namespace => $unused) {
            $prefix = $this->namespaceToPrefix[$namespace];
            if ($prefix === 'rdf') {
                continue;
            }

            $this->writer->writeAttribute('xmlns:' . $prefix, $namespace);
        }

        $this->frameStack[] = ['type' => self::FRAME_ROOT];
    }

    /** @return int|null Index in $frameStack of the nearest subject frame that matches $subject. */
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
     */
    private function closeFramesAbove(int $index): void
    {
        for ($i = count($this->frameStack) - 1; $i > $index; $i--) {
            $this->writer->endElement();
            array_pop($this->frameStack);
        }
    }

    /**
     * Closes all frames down to the root frame, but not including it.
     */
    private function closeUntilRoot(): void
    {
        for ($i = count($this->frameStack) - 1; $i >= 0; $i--) {
            $frame = $this->frameStack[$i];
            if ($frame['type'] === self::FRAME_ROOT) {
                break;
            }

            $this->writer->endElement();
            array_pop($this->frameStack);
        }
    }

    private function openSubjectElement(Iri|BlankNode $subject): void
    {
        $rdfNamespace = RdfXmlParser::RDF_NAMESPACE;

        $this->writer->startElementNs('rdf', 'Description', $rdfNamespace);

        if ($subject instanceof Iri) {
            $this->writer->writeAttributeNs('rdf', 'about', $rdfNamespace, $subject->iri);
        } else {
            $this->writer->writeAttributeNs('rdf', 'nodeID', $rdfNamespace, $subject->identifier);
        }

        $this->frameStack[] = [
            'type' => self::FRAME_SUBJECT,
            'subject' => $subject,
        ];
    }

    private function writePredicateAndObject(Iri $predicate, Iri|Literal|BlankNode $object): void
    {
        [$namespace, $localName] = $this->splitNamespaceAndLocalName($predicate->iri);
        $prefix                  = $this->ensurePrefixForNamespace($namespace);

        $this->writer->startElementNs($prefix, $localName, $namespace);

        if (array_key_exists($namespace, $this->needsLocalDeclaration)) {
            $this->writer->writeAttribute('xmlns:' . $prefix, $namespace);
            unset($this->needsLocalDeclaration[$namespace]);
        }

        $this->frameStack[] = ['type' => self::FRAME_PROPERTY];

        if ($object instanceof Literal) {
            $this->writeLiteralObject($object);

            $this->writer->endElement();
            array_pop($this->frameStack);

            return;
        }

        $this->openSubjectElement($object);
    }

    private function writeLiteralObject(Literal $literal): void
    {
        $rdfNamespace = RdfXmlParser::RDF_NAMESPACE;

        if ($literal->language !== null) {
            $this->writer->writeAttributeNs('xml', 'lang', XMLUtils::XML_NAMESPACE, $literal->language);
        } elseif ($literal->datatype->iri !== XSDString::IRI) {
            $this->writer->writeAttributeNs('rdf', 'datatype', $rdfNamespace, $literal->datatype->iri);
        }

        $this->writer->text($literal->lexical);
    }

    /**
     * @param non-empty-string $iri
     *
     * @return array{0: non-empty-string, 1: non-empty-string} [namespace, localName]
     */
    private function splitNamespaceAndLocalName(string $iri): array
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
     * Ensures that there is a prefix for the given namespace URI and returns it.
     *
     * @param non-empty-string $namespace
     *
     * @return non-empty-string
     */
    private function ensurePrefixForNamespace(string $namespace): string
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
}
