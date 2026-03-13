<?php

declare(strict_types=1);

namespace FancyRDF\Formats;

use FancyRDF\Dataset\Quad;
use FancyRDF\Term\BlankNode;
use FancyRDF\Term\Datatype\XSDString;
use FancyRDF\Term\Iri;
use FancyRDF\Term\Literal;
use FancyRDF\Xml\XMLUtils;
use InvalidArgumentException;
use Override;
use RuntimeException;
use XMLWriter;

use function array_search;

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
final class RdfXmlSerializer extends FrameSerializer
{
    /**
     * @param array<string, non-empty-string> $prefixes
     *   A mapping of XML prefixes to namespace URIs that should be declared on the root element.
     */
    public function __construct(
        private XMLWriter $writer,
        array $prefixes = [],
    ) {
        $rdfNamespace = RdfXmlParser::RDF_NAMESPACE;

        $allPrefixes = ['rdf' => $rdfNamespace];

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

            if (isset($allPrefixes[$prefix]) && $allPrefixes[$prefix] !== $namespace) {
                throw new RuntimeException('Prefix "' . $prefix . '" already mapped to a different namespace');
            }

            $existingPrefix = array_search($namespace, $allPrefixes, true);
            if ($existingPrefix !== false && $existingPrefix !== $prefix) {
                throw new RuntimeException('Namespace "' . $namespace . '" already mapped to a different prefix');
            }

            $allPrefixes[$prefix] = $namespace;
        }

        parent::__construct($allPrefixes);
    }

    /**
     * Writes a single triple as RDF/XML.
     *
     * @param TripleArray $triple
     */
    public function write(array $triple): void
    {
        [$subject, $predicate, $object] = $triple;

        $this->writeQuad([$subject, $predicate, $object, null]);
    }

    #[Override]
    protected function doStartDocument(): void
    {
        $this->writer->setIndent(true);
        $this->writer->setIndentString('  ');

        $this->writer->startDocument('1.0', 'UTF-8');
    }

    #[Override]
    protected function doEndDocument(): void
    {
        $this->writer->endDocument();
    }

    /** @param array<string, non-empty-string> $namespaces */
    #[Override]
    protected function doOpenRoot(array $namespaces): void
    {
        $this->writer->startElementNs('rdf', 'RDF', RdfXmlParser::RDF_NAMESPACE);

        foreach ($namespaces as $namespace => $prefix) {
            if ($prefix === 'rdf') {
                continue;
            }

            $this->writer->writeAttribute('xmlns:' . $prefix, $namespace);
        }
    }

    #[Override]
    protected function doCloseRoot(): void
    {
        $this->writer->endElement();
    }

    #[Override]
    protected function doOpenGraph(Iri|BlankNode $graph): void
    {
        throw new InvalidArgumentException('RDF/XML cannot serialize quads, only triples are supported. ');
    }

    #[Override]
    protected function doCloseGraph(Iri|BlankNode $graph): void
    {
        throw new InvalidArgumentException('RDF/XML cannot serialize quads, only triples are supported. ');
    }

    #[Override]
    protected function doOpenSubject(Iri|BlankNode $subject): void
    {
        $this->writer->startElementNs('rdf', 'Description', RdfXmlParser::RDF_NAMESPACE);

        if ($subject instanceof Iri) {
            $this->writer->writeAttributeNs('rdf', 'about', RdfXmlParser::RDF_NAMESPACE, $subject->iri);
        } else {
            $this->writer->writeAttributeNs('rdf', 'nodeID', RdfXmlParser::RDF_NAMESPACE, $subject->identifier);
        }
    }

    #[Override]
    protected function doCloseSubject(Iri|BlankNode $subject): void
    {
        $this->writer->endElement();
    }

    #[Override]
    protected function doOpenProperty(Iri $predicate, string $namespace, string $localName, string $prefix): void
    {
        $this->writer->startElementNs($prefix, $localName, $namespace);

        if (! $this->namespaceNeedsLocalDeclaration($namespace)) {
            return;
        }

        $this->writer->writeAttribute('xmlns:' . $prefix, $namespace);
        $this->markNamespaceDeclaredLocally($namespace);
    }

    #[Override]
    protected function doCloseProperty(Iri $predicate): void
    {
        $this->writer->endElement();
    }

    #[Override]
    protected function doLiteral(Literal $literal): void
    {
        if ($literal->language !== null) {
            $this->writer->writeAttributeNs('xml', 'lang', XMLUtils::XML_NAMESPACE, $literal->language);
        } elseif ($literal->datatype->iri !== XSDString::IRI) {
            $this->writer->writeAttributeNs('rdf', 'datatype', RdfXmlParser::RDF_NAMESPACE, $literal->datatype->iri);
        }

        $this->writer->text($literal->lexical);
    }
}
