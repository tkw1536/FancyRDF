<?php

declare(strict_types=1);

namespace FancySparql\Formats;

use DOMDocument;
use Exception;
use FancySparql\Dataset\Quad;
use FancySparql\Term\Literal;
use FancySparql\Term\Resource;
use FancySparql\Xml\XMLUtils;
use Fiber;
use IteratorAggregate;
use Override;
use Traversable;
use XMLReader;

use function array_key_last;
use function array_pop;
use function assert;

use const LIBXML_NOERROR;
use const LIBXML_NOWARNING;

/**
 * @phpstan-import-type TripleArray from Quad
 * @implements IteratorAggregate<int|string, TripleArray>
 */
class RdfXmlParser implements IteratorAggregate
{
    public const string RDF_NAMESPACE = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';

    /** @param XMLReader $reader The XMLReader instance to parse from */
    public function __construct(private XMLReader $reader)
    {
    }

    /** @var bool if the getIterator() method has been previously called */
    private bool $getIteratorStarted = false;

    /** @var Fiber<void, TripleArray, void, TripleArray>|null The fiber used for parsing */
    private Fiber|null $fiber = null;

    /** @return Traversable<int|string, TripleArray> */
    #[Override]
    public function getIterator(): Traversable
    {
        // prevent multiple calls to getIterator
        if ($this->getIteratorStarted) {
            throw new Exception('Can only use getIterator() once');
        }

        $this->getIteratorStarted = true;

        // Create a fiber to run the parsing logic
        $this->fiber = new Fiber(function (): void {
            $this->doParse();
        });

        // Start the fiber - it may suspend immediately with a value
        $value = $this->fiber->start();
        if ($value !== null) {
            yield $value;
        }

        // Continue resuming the fiber and yielding values as they are emitted
        while (! $this->fiber->isTerminated()) {
            $value = $this->fiber->resume();
            if ($value === null) {
                continue;
            }

            yield $value;
        }
    }

    /**
     * Resolves a URI against the current xml:base.
     *
     * The code assumes that the result of joining the URL is a valid non-empty string.
     *
     * @param string $uri The URI to resolve (may be empty, relative, or absolute)
     *
     * @return non-empty-string The resolved absolute URI
     */
    private function resolveURI(string $uri): string
    {
        $base = $this->reader->baseURI;

        if ($base === '') {
            assert($uri !== '', 'URI must be non-empty');

            return $uri;
        }

        $result = Resource::joinURLs($base, $uri);
        assert($result !== '', 'URI must be non-empty');

        return $result;
    }

    private int $bnodeCounter = 0;

    /** return a new blank node */
    private function nextBNode(): Resource
    {
        $this->bnodeCounter++;

        return new Resource('_:b' . $this->bnodeCounter);
    }

    /** @var Resource|null the currently active subject */
    private Resource|null $subject = null;

    /** @var list<array{subject: Resource|null, depth: int}> a stack of subjects within the current nesting level */
    private array $subjectStack = [];

    /**
     * Emits a set of quads by suspending the fiber.
     *
     * @param TripleArray $quads The quads to emit
     */
    private function emit(array ...$quads): void
    {
        foreach ($quads as $quad) {
            Fiber::suspend($quad);
        }
    }

    /**
     * Emits reification triples for a statement.
     *
     * @param non-empty-string $reificationURI The URI of the reification statement
     * @param Resource         $subject        The subject of the original triple
     * @param Resource         $predicate      The predicate of the original triple
     * @param Resource|Literal $object         The object of the original triple
     */
    private function emitReification(string $reificationURI, Resource $subject, Resource $predicate, Resource|Literal $object): void
    {
        $statement         = new Resource($reificationURI);
        $typeResource      = new Resource(self::RDF_NAMESPACE . 'type');
        $statementType     = new Resource(self::RDF_NAMESPACE . 'Statement');
        $subjectResource   = new Resource(self::RDF_NAMESPACE . 'subject');
        $predicateResource = new Resource(self::RDF_NAMESPACE . 'predicate');
        $objectResource    = new Resource(self::RDF_NAMESPACE . 'object');

        $this->emit(
            [$statement, $typeResource, $statementType, null],
            [$statement, $subjectResource, $subject, null],
            [$statement, $predicateResource, $predicate, null],
            [$statement, $objectResource, $object, null],
        );
    }

    /**
     * Function that does the actual parsing.
     */
    private function doParse(): void
    {
        while ($this->reader->read()) {
            // If an element closes, and we are back at the right depth, then we need to pop the stack.
            // And go back to the previous subject and base.
            if ($this->reader->nodeType === XMLReader::END_ELEMENT) {
                $lastKey = array_key_last($this->subjectStack);
                if (
                    $lastKey !== null &&
                    $this->subjectStack !== [] &&
                    $this->subjectStack[$lastKey]['depth'] === $this->reader->depth
                ) {
                    $popped        = array_pop($this->subjectStack);
                    $this->subject = $popped['subject'];
                }

                continue;
            }

            if ($this->reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }

            $localName = $this->reader->localName;
            $namespace = $this->reader->namespaceURI;

            // rdf:Description block starting
            if ($namespace === self::RDF_NAMESPACE && $localName === 'Description') {
                $descriptionDepth     = $this->reader->depth;
                $this->subjectStack[] = ['subject' => $this->subject, 'depth' => $descriptionDepth];

                $about  = $this->reader->getAttribute('rdf:about') ?? $this->reader->getAttribute('about');
                $idAttr = $this->reader->getAttribute('rdf:ID');

                if ($idAttr !== null) {
                    // rdf:ID resolves to base + #id
                    $resolvedURI   = $this->resolveURI('#' . $idAttr);
                    $this->subject = new Resource($resolvedURI);
                } elseif ($about !== null) {
                    // rdf:about - empty string resolves to base URI
                    $resolvedURI   = $this->resolveURI($about);
                    $this->subject = new Resource($resolvedURI);
                } else {
                    $this->subject = $this->nextBNode();
                }

                // Process attributes on Description element as property-value pairs
                if ($this->reader->hasAttributes) {
                    $this->reader->moveToFirstAttribute();
                    do {
                        $attrLocalName = $this->reader->localName;
                        $attrNamespace = $this->reader->namespaceURI;

                        // Skip RDF namespace attributes and xml:base
                        if ($attrNamespace === self::RDF_NAMESPACE || $attrNamespace === XMLUtils::XML_NAMESPACE || $attrNamespace === '') {
                            continue;
                        }

                        $predicate = new Resource($attrNamespace . $attrLocalName);
                        $value     = $this->reader->value;

                        assert($this->subject !== null, 'subject must be set when processing Description attributes');

                        $this->emit([
                            $this->subject,
                            $predicate,
                            new Literal($value),
                            null,
                        ]);
                    } while ($this->reader->moveToNextAttribute());

                    $this->reader->moveToElement();
                }

                // If self-closing, pop stacks immediately
                if ($this->reader->isEmptyElement) {
                    $lastKey = array_key_last($this->subjectStack);
                    if ($lastKey !== null && $this->subjectStack[$lastKey]['depth'] === $descriptionDepth) {
                        $popped = array_pop($this->subjectStack);
                        assert($popped !== null);
                        $this->subject = $popped['subject'];
                    }
                }

                continue;
            }

            // Property element (check first, as property elements can have rdf:ID for reification)
            if ($this->subject !== null && $namespace !== '' && $namespace !== self::RDF_NAMESPACE) {
                $predicate    = new Resource($namespace . $localName);
                $resourceAttr = $this->reader->getAttribute('rdf:resource') ?? $this->reader->getAttribute('resource');
                $parseType    = $this->reader->getAttribute('rdf:parseType');
                $idAttr       = $this->reader->getAttribute('rdf:ID');

                // Handle rdf:ID on property element (reification)
                $reificationURI = null;
                if ($idAttr !== null) {
                    $reificationURI = $this->resolveURI('#' . $idAttr);
                }

                // Handle rdf:parseType="Literal" - read inner XML content and canonicalize
                if ($parseType === 'Literal') {
                    // Use readOuterXml to get the element with full context
                    $outerXml = $this->reader->readOuterXml();

                    // Canonicalize: add namespace declarations from ancestor elements
                    $canonicalXml = $this->canonicalizeXmlLiteral($outerXml);
                    $object       = new Literal($canonicalXml, null, self::RDF_NAMESPACE . 'XMLLiteral');

                    $this->emit([
                        $this->subject,
                        $predicate,
                        $object,
                        null,
                    ]);

                    // If rdf:ID is present, create reification triples
                    if ($reificationURI !== null) {
                        assert($this->subject !== null, 'subject must be set for reification');
                        $this->emitReification($reificationURI, $this->subject, $predicate, $object);
                    }

                    continue;
                }

                if ($resourceAttr !== null) {
                    $resolvedURI = $this->resolveURI($resourceAttr);
                    $object      = new Resource($resolvedURI);

                    $this->emit([
                        $this->subject,
                        $predicate,
                        $object,
                        null,
                    ]);

                    // If rdf:ID is present, create reification triples
                    if ($reificationURI !== null) {
                        assert($this->subject !== null, 'subject must be set for reification');
                        $this->emitReification($reificationURI, $this->subject, $predicate, $object);
                    }

                    continue;
                }

                // Read text content
                $this->reader->read();
                if (
                    $this->reader->nodeType !== XMLReader::TEXT &&
                    $this->reader->nodeType !== XMLReader::CDATA
                ) {
                    // If rdf:ID is present but no content, still create reification
                    if (
                        $reificationURI !== null
                    ) {
                        // Create a blank node for the object if there's no content
                        $object = $this->nextBNode();

                        $this->emit([$this->subject, $predicate, $object, null]);

                        assert($this->subject !== null, 'subject must be set for reification');
                        $this->emitReification($reificationURI, $this->subject, $predicate, $object);
                    }

                    continue;
                }

                $object = new Literal($this->reader->value);

                $this->emit([
                    $this->subject,
                    $predicate,
                    $object,
                    null,
                ]);

                // If rdf:ID is present, create reification triples
                if ($reificationURI !== null) {
                    assert($this->subject !== null, 'subject must be set for reification');
                    $this->emitReification($reificationURI, $this->subject, $predicate, $object);
                }

                continue;
            }

            // Typed node (e.g. <ex:Book rdf:about="...">) – only when it has node-identifying attributes
            // Must check after property elements, as property elements can have rdf:ID for reification
            $about         = $this->reader->getAttribute('rdf:about') ?? $this->reader->getAttribute('about');
            $nodeId        = $this->reader->getAttribute('rdf:nodeID');
            $idAttr        = $this->reader->getAttribute('rdf:ID');
            $isNodeElement = $about !== null || $nodeId !== null || ($idAttr !== null && $this->subject === null);

            if ($namespace !== self::RDF_NAMESPACE && $localName !== 'RDF' && $isNodeElement) {
                $typedNodeDepth = $this->reader->depth;

                if ($idAttr !== null) {
                    // rdf:ID resolves to base + #id
                    $resolvedURI = $this->resolveURI('#' . $idAttr);
                    $subject     = new Resource($resolvedURI);
                } elseif ($about !== null) {
                    // rdf:about - empty string resolves to base URI
                    $resolvedURI = $this->resolveURI($about);
                    $subject     = new Resource($resolvedURI);
                } elseif ($nodeId !== null) {
                    $subject = new Resource('_:' . $nodeId);
                } else {
                    $subject = $this->nextBNode();
                }

                $this->subjectStack[] = ['subject' => $this->subject, 'depth' => $typedNodeDepth];
                $this->subject        = $subject;

                $object = $namespace . $localName;
                assert($object !== '', 'object may not be empty');

                $this->emit([
                    $subject,
                    new Resource(self::RDF_NAMESPACE . 'type'),
                    new Resource($object),
                    null,
                ]);

                // If self-closing, pop stacks immediately
                if ($this->reader->isEmptyElement) {
                    // we know the subject stack is no-empty because we pushed to it above!
                    $lastKey = array_key_last($this->subjectStack);
                    if ($lastKey !== null && $this->subjectStack[$lastKey]['depth'] === $typedNodeDepth) {
                        $popped = array_pop($this->subjectStack);
                        assert($popped !== null);
                        $this->subject = $popped['subject'];
                    }
                }

                continue;
            }

            $iri = $namespace . $localName;
            assert($iri !== '', 'iri must be non-empty');
            $predicate    = new Resource($iri);
            $resourceAttr = $this->reader->getAttribute('rdf:resource') ?? $this->reader->getAttribute('resource');
            $idAttr       = $this->reader->getAttribute('rdf:ID');

            // Handle rdf:ID on property element (reification)
            $reificationURI = null;
            if ($idAttr !== null) {
                $reificationURI = $this->resolveURI('#' . $idAttr);
            }

            if ($resourceAttr !== null) {
                $resolvedURI = $this->resolveURI($resourceAttr);
                $object      = new Resource($resolvedURI);

                assert($this->subject !== null, 'subject must be set');

                $this->emit([
                    $this->subject,
                    $predicate,
                    $object,
                    null,
                ]);

                // If rdf:ID is present, create reification triples
                if ($reificationURI !== null) {
                    assert($this->subject !== null, 'subject must be set for reification');
                    $this->emitReification($reificationURI, $this->subject, $predicate, $object);
                }

                continue;
            }

            // Read text content
            $this->reader->read();
            if (
                $this->reader->nodeType !== XMLReader::TEXT &&
                $this->reader->nodeType !== XMLReader::CDATA
            ) {
                // If rdf:ID is present but no content, still create reification
                if ($reificationURI !== null && $this->subject !== null) {
                    // Create a blank node for the object if there's no content
                    $object = $this->nextBNode();

                    $this->emit([
                        $this->subject,
                        $predicate,
                        $object,
                        null,
                    ]);

                    assert($this->subject !== null, 'subject must be set for reification');
                    $this->emitReification($reificationURI, $this->subject, $predicate, $object);
                }

                continue;
            }

            $object = new Literal($this->reader->value);

            assert($this->subject !== null, 'subject must be set');

            $this->emit([
                $this->subject,
                $predicate,
                $object,
                null,
            ]);

            // If rdf:ID is present, create reification triples
            if ($reificationURI === null) {
                continue;
            }

            assert($this->subject !== null, 'subject must be set for reification');
            $this->emitReification($reificationURI, $this->subject, $predicate, $object);
        }
    }

    /**
     * Canonicalizes XML literal content by adding namespace declarations from ancestor elements.
     *
     * @param string $outerXml The outer XML of the property element (includes full context)
     *
     * @return string The canonicalized XML with namespace declarations
     */
    private function canonicalizeXmlLiteral(string $outerXml): string
    {
        // Parse the outer XML into a new document
        $dom = new DOMDocument();
        $dom->loadXML($outerXml, LIBXML_NOERROR | LIBXML_NOWARNING);
        $outerElement = $dom->documentElement;
        if ($outerElement === null) {
            throw new Exception('Failed to parse outer XML');
        }

        // Get the inner element (first child element)
        $innerNode = $outerElement->firstChild;
        if ($innerNode === null) {
            return '';
        }

        // Return the serialized inner XML
        $result = $innerNode->C14N();
        if ($result === false) {
            throw new Exception('Failed to canonicalize inner XML');
        }

        return $result;
    }
}
