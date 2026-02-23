<?php

declare(strict_types=1);

namespace FancyRDF\Formats;

use FancyRDF\Dataset\Quad;
use FancyRDF\Term\BlankNode;
use FancyRDF\Term\Iri;
use FancyRDF\Term\Literal;
use FancyRDF\Uri\UriReference;
use FancyRDF\Xml\XMLUtils;
use Override;
use SplStack;
use XMLReader;

use function assert;
use function count;
use function in_array;
use function is_string;
use function mb_substr;
use function preg_match;
use function str_contains;

/**
 * A streaming RDF/XML parser that emits triples as they are encountered.
 *
 * @phpstan-import-type TripleArray from Quad
 * @extends FiberIterator<TripleArray>
 */
class RdfXmlParser extends FiberIterator
{
    public const string RDF_NAMESPACE = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';

    /** @param XMLReader $reader The XMLReader instance to parse from */
    public function __construct(private XMLReader $reader)
    {
        $this->subjectStack = new SplStack();
    }

    // =================================
    // URI handling
    // =================================

    /**
     * Resolves a URI against the xml:base attribute of the reader.
     *
     * @param string $uri
     *  The URI to resolve.
     *
     * @return non-empty-string
     *   The resolved absolute URI.
     *   The function asserts that the URI is resolvable, i.e. that the result is non-empty.
     */
    private function resolveURI(string $uri): string
    {
        $resolved = UriReference::resolveRelative($this->reader->baseURI, $uri);
        assert(
            $resolved !== '' && self::isValidRDFIri($resolved),
            'resolved URI must be a valid absolute IRI: ' . $resolved,
        );

        return $resolved;
    }

    private static function isValidRDFIri(string $iri): bool
    {
        $ref = UriReference::parse($iri);

        return ! $ref->isRelativeReference() && $ref->isRFC3987IriReference();
    }

    // ===========================
    // Blank node handling
    // ===========================

    private int $blankNodeCounter = 0;

    /** @var array<string, non-empty-string> */
    private array $blankNodeMap = [];

    /**
     * Makes a blank node resource.
     *
     * @param string|null $name
     *   If non-null, a string that uniquely identifies this blank node
     *   within the current document.
     *   If null, a fresh blank node label is returned.
     */
    private function makeBlankNode(string|null $name): BlankNode
    {
        // Pick the existing blank node label, or create a new one.
        $id   = is_string($name) ? $this->blankNodeMap[$name] ?? null : null;
        $id ??= 'b' . ($this->blankNodeCounter++);

        // Store the mapping if we were given a name.
        if (is_string($name)) {
            $this->blankNodeMap[$name] = $id;
        }

        return new BlankNode($id);
    }

    // ===========================
    // Unique identifier handling
    // ===========================

    /** @var array<string, true> */
    private array $seenIds = [];

    /**
     * Checks that the given identifier was not previously seen.
     * An identifier is considered seen once it has been passed to this function.
     */
    private function sawIdentifier(string $identifier): bool
    {
        if (isset($this->seenIds[$identifier])) {
            return true;
        }

        $this->seenIds[$identifier] = true;

        return false;
    }

    // ===========================
    // List item handling
    // ===========================

    /** @var array<int, int> li counter per depth level */
    private array $liCounters = [];

    /** return the next li property name for the current depth */
    private function nextLiProperty(): Iri
    {
        $depth = $this->reader->depth;
        if (! isset($this->liCounters[$depth])) {
            $this->liCounters[$depth] = 0;
        }

        $this->liCounters[$depth]++;

        return new Iri(self::RDF_NAMESPACE . '_' . $this->liCounters[$depth]);
    }

    /** @var Iri|BlankNode|null the currently active subject */
    private Iri|BlankNode|null $subject = null;

    /** @var SplStack<array{subject: Iri|BlankNode|null, depth: int}> a stack of subjects within the current nesting level */
    private SplStack $subjectStack;

    /** @var array{subject: Iri|BlankNode, predicate: Iri, depth: int, reificationURI: non-empty-string|null}|null pending property waiting for nested object */
    private array|null $pendingProperty = null;

    /**
     * Emits a triple and handles reification if a reification URI is provided.
     *
     * @param Iri|BlankNode|null    $subject        The subject of the triple (may be null when assertions are disabled)
     * @param Iri                   $predicate      The predicate of the triple
     * @param Iri|Literal|BlankNode $object         The object of the triple
     * @param non-empty-string|null $reificationURI The URI for reification, or null if none
     */
    private function emitTripleWithReification(Iri|BlankNode|null $subject, Iri $predicate, Iri|Literal|BlankNode $object, string|null $reificationURI): void
    {
        if ($subject === null) {
            return;
        }

        $this->emit([
            $subject,
            $predicate,
            $object,
            null,
        ]);

        if ($reificationURI === null) {
            return;
        }

        $statement         = new Iri($reificationURI);
        $typeResource      = new Iri(self::RDF_NAMESPACE . 'type');
        $statementType     = new Iri(self::RDF_NAMESPACE . 'Statement');
        $subjectResource   = new Iri(self::RDF_NAMESPACE . 'subject');
        $predicateResource = new Iri(self::RDF_NAMESPACE . 'predicate');
        $objectResource    = new Iri(self::RDF_NAMESPACE . 'object');

        $this->emit(
            [$statement, $typeResource, $statementType, null],
            [$statement, $subjectResource, $subject, null],
            [$statement, $predicateResource, $predicate, null],
            [$statement, $objectResource, $object, null],
        );
    }

    /**
     * Resolves a subject Resource from rdf:about, rdf:nodeID, or rdf:ID attributes.
     *
     * @param string|null $about           The rdf:about attribute value
     * @param string|null $nodeId          The rdf:nodeID attribute value
     * @param string|null $idAttr          The rdf:ID attribute value
     * @param bool        $checkDuplicates Whether to check for duplicate rdf:ID values
     *
     * @return Iri|BlankNode The resolved subject Resource
     */
    private function resolveSubject(string|null $about, string|null $nodeId, string|null $idAttr, bool $checkDuplicates = false): Iri|BlankNode
    {
        if ($idAttr !== null) {
            // Validate rdf:ID value matches XML Name production
            assert(self::isValidXmlName($idAttr), 'rdf:ID value must match XML Name production: ' . $idAttr);

            $resolvedURI = $this->resolveURI('#' . $idAttr);
            assert(! $checkDuplicates || ! $this->sawIdentifier($resolvedURI), 'Duplicate rdf:ID value: ' . $idAttr);

            return new Iri($resolvedURI);
        }

        if ($about !== null) {
            // rdf:about - empty string resolves to base URI
            $resolvedURI = $this->resolveURI($about);

            return new Iri($resolvedURI);
        }

        if ($nodeId !== null) {
            // Validate rdf:nodeID value matches XML Name production
            assert(self::isValidXmlName($nodeId), 'rdf:nodeID value must match XML Name production: ' . $nodeId);

            // rdf:nodeID creates a blank node with the given ID
            return $this->makeBlankNode($nodeId);
        }

        // No identifying attributes - create blank node
        return $this->makeBlankNode(null);
    }

    /**
     * Handles rdf:parseType="Collection" - creates a list structure from child elements.
     *
     * @param Iri|BlankNode         $subject        The subject of the collection property
     * @param Iri                   $predicate      The predicate of the collection property
     * @param non-empty-string|null $reificationURI The URI for reification, or null if none
     */
    private function handleParseTypeCollection(Iri|BlankNode $subject, Iri $predicate, string|null $reificationURI): void
    {
        $listHead = $this->makeBlankNode(null);
        $this->emitTripleWithReification($subject, $predicate, $listHead, $reificationURI);

        // Process collection items
        $currentList = $listHead;
        $itemDepth   = $this->reader->depth;
        $items       = [];
        while ($this->reader->read()) {
            // Stop when we reach the closing tag of the property element
            if ($this->reader->nodeType === XMLReader::END_ELEMENT && $this->reader->depth === $itemDepth) {
                break;
            }

            // Process each child element as a list item
            if ($this->reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }

            $itemAbout  = $this->reader->getAttribute('rdf:about') ?? $this->reader->getAttribute('about');
            $itemNodeId = $this->reader->getAttribute('rdf:nodeID');
            $itemId     = $this->reader->getAttribute('rdf:ID');

            $itemSubject = $this->resolveSubject($itemAbout, $itemNodeId, $itemId);

            $items[] = $itemSubject;

            // Skip past this element
            if ($this->reader->isEmptyElement) {
                continue;
            }

            // Skip to end of element
            $itemElementDepth = $this->reader->depth;
            while ($this->reader->read()) {
                if ($this->reader->nodeType === XMLReader::END_ELEMENT && $this->reader->depth === $itemElementDepth) {
                    break;
                }
            }
        }

        // Build list structure
        foreach ($items as $index => $itemSubject) {
            // Emit rdf:first triple
            $this->emit([
                $currentList,
                new Iri(self::RDF_NAMESPACE . 'first'),
                $itemSubject,
                null,
            ]);

            // If not the last item, create next list node
            if ($index < count($items) - 1) {
                $nextList = $this->makeBlankNode(null);
                $this->emit([
                    $currentList,
                    new Iri(self::RDF_NAMESPACE . 'rest'),
                    $nextList,
                    null,
                ]);
                $currentList = $nextList;
            } else {
                // Last item - point rest to nil
                $this->emit([
                    $currentList,
                    new Iri(self::RDF_NAMESPACE . 'rest'),
                    new Iri(self::RDF_NAMESPACE . 'nil'),
                    null,
                ]);
            }
        }

        // Handle empty collection
        if (count($items) !== 0) {
            return;
        }

        $this->emit([
            $currentList,
            new Iri(self::RDF_NAMESPACE . 'rest'),
            new Iri(self::RDF_NAMESPACE . 'nil'),
            null,
        ]);
    }

    /**
     * Handles rdf:parseType="Resource" - creates a blank node and processes nested content.
     *
     * @param Iri|BlankNode         $subject        The subject of the resource property
     * @param Iri                   $predicate      The predicate of the resource property
     * @param non-empty-string|null $reificationURI The URI for reification, or null if none
     */
    private function handleParseTypeResource(Iri|BlankNode $subject, Iri $predicate, string|null $reificationURI): void
    {
        $resourceObject = $this->makeBlankNode(null);
        $this->emitTripleWithReification($subject, $predicate, $resourceObject, $reificationURI);

        // Push current subject and set the resource object as new subject
        $resourceDepth = $this->reader->depth;
        $this->subjectStack->push(['subject' => $subject, 'depth' => $resourceDepth]);
        $this->subject = $resourceObject;

        // Skip past this element's opening tag - nested content will be processed in main loop
        if (! $this->reader->isEmptyElement) {
            return;
        }

        if ($this->subjectStack->isEmpty() || $this->subjectStack->top()['depth'] !== $resourceDepth) {
            return;
        }

        $popped        = $this->subjectStack->pop();
        $this->subject = $popped['subject'];
    }

    /**
     * Handles rdf:parseType="Literal" - reads inner XML content and canonicalizes it.
     *
     * @param Iri|BlankNode         $subject        The subject of the literal property
     * @param Iri                   $predicate      The predicate of the literal property
     * @param non-empty-string|null $reificationURI The URI for reification, or null if none
     * @param string|null           $resourceAttr   The rdf:resource attribute value (must be null)
     */
    private function handleParseTypeLiteral(Iri|BlankNode $subject, Iri $predicate, string|null $reificationURI, string|null $resourceAttr): void
    {
        // Error: rdf:parseType="Literal" cannot be combined with rdf:resource
        assert($resourceAttr === null, 'rdf:parseType="Literal" cannot be combined with rdf:resource attribute');

        // Error: rdf:parseType="Literal" cannot be combined with non-RDF attributes
        if ($this->reader->hasAttributes) {
            $this->reader->moveToFirstAttribute();
            do {
                $attrNamespace = $this->reader->namespaceURI;
                $attrLocalName = $this->reader->localName;
                // Check if it's a non-RDF, non-XML attribute
                assert(
                    $attrNamespace === self::RDF_NAMESPACE
                    || $attrNamespace === XMLUtils::XML_NAMESPACE
                    || $attrNamespace === XMLUtils::XMLNS_NAMESPACE
                    || $attrNamespace === ''
                    || in_array($attrLocalName, ['resource', 'ID', 'nodeID', 'parseType', 'datatype'], true),
                    'rdf:parseType="Literal" cannot be combined with non-RDF attributes',
                );
            } while ($this->reader->moveToNextAttribute());

            $this->reader->moveToElement();
        }

        $outerXml = $this->reader->readOuterXml();

        $canonicalXml = XMLUtils::serializerInnerXML($outerXml);
        $object       = new Literal($canonicalXml, null, self::RDF_NAMESPACE . 'XMLLiteral');

        $this->emitTripleWithReification($subject, $predicate, $object, $reificationURI);

        // Skip past this element so the main loop does not re-parse inner content as RDF
        $this->reader->next();
    }

    /**
     * Processes non-RDF attributes on a property element as properties of the object.
     *
     * @param Iri|BlankNode         $object The object (IRI or Blank Node) to add properties to
     * @param non-empty-string|null $lang   The language for literal values
     */
    private function processPropertyElementAttributes(Iri|BlankNode $object, string|null $lang): void
    {
        if (! $this->reader->hasAttributes) {
            return;
        }

        $this->reader->moveToFirstAttribute();
        do {
            $attrNamespace = $this->reader->namespaceURI;
            $attrLocalName = $this->reader->localName;
            $attrValue     = $this->reader->value;

            // Skip RDF namespace attributes, xml:base, xml:lang, and xmlns declarations
            if (
                $attrNamespace === self::RDF_NAMESPACE
                || $attrNamespace === XMLUtils::XML_NAMESPACE
                || $attrNamespace === XMLUtils::XMLNS_NAMESPACE
                || $attrNamespace === ''
            ) {
                continue;
            }

            $attrPredicate = new Iri($attrNamespace . $attrLocalName);
            $attrObject    = new Literal($attrValue, $lang);

            $this->emit([
                $object,
                $attrPredicate,
                $attrObject,
                null,
            ]);
        } while ($this->reader->moveToNextAttribute());

        $this->reader->moveToElement();
    }

    /**
     * Function that does the actual parsing.
     */
    #[Override]
    protected function doIterate(): void
    {
        while ($this->reader->read()) {
            // If an element closes, and we are back at the right depth, then we need to pop the stack.
            // And go back to the previous subject and base.
            if ($this->reader->nodeType === XMLReader::END_ELEMENT) {
                if (
                    ! $this->subjectStack->isEmpty() &&
                    $this->subjectStack->top()['depth'] === $this->reader->depth
                ) {
                    $popped             = $this->subjectStack->pop();
                    $thePendingProperty = $this->pendingProperty;
                    // If there's a pending property, emit the triple with the closing element's subject as object
                    if ($thePendingProperty !== null && $thePendingProperty['depth'] === $this->reader->depth) {
                        assert($this->subject !== null, 'subject must be set for pending property');
                        $object = $this->subject;
                        $this->emitTripleWithReification($thePendingProperty['subject'], $thePendingProperty['predicate'], $object, $thePendingProperty['reificationURI']);

                        $this->pendingProperty = null;
                    }

                    $this->subject = $popped['subject'];
                }

                // Clean up li counter for container elements when they close
                $closingLocalName   = $this->reader->localName;
                $closingNamespace   = $this->reader->namespaceURI;
                $isContainerClosing = $closingNamespace === self::RDF_NAMESPACE && in_array($closingLocalName, ['Bag', 'Seq', 'Alt', 'List'], true);
                if ($isContainerClosing) {
                    unset($this->liCounters[$this->reader->depth]);
                }

                continue;
            }

            if ($this->reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }

            $localName = $this->reader->localName;
            $namespace = $this->reader->namespaceURI;

            // Property element (check first, as property elements can have rdf:ID for reification)
            // Also handle rdf:li elements which should be converted to numbered properties
            // RDF container elements (Bag, Seq, Alt, List) can be property elements when there's a subject
            // Error: Certain RDF namespace elements cannot be used as property elements
            // NOTE: rdf:Description is only forbidden as a property element when it has rdf:resource
            if ($this->subject !== null && $namespace === self::RDF_NAMESPACE) {
                assert($localName !== 'RDF', 'rdf:RDF cannot be used as a property element');
                // rdf:ID, rdf:about, rdf:resource, rdf:parseType, rdf:datatype, rdf:nodeID, rdf:bagID, rdf:aboutEach, rdf:aboutEachPrefix are attributes/elements that cannot be property elements
                // Note: rdf:Description is handled separately below
                assert(! in_array($localName, ['ID', 'about', 'resource', 'parseType', 'datatype', 'nodeID', 'bagID', 'aboutEach', 'aboutEachPrefix'], true), 'rdf:' . $localName . ' cannot be used as a property element');
                // rdf:Description cannot be used as a property element (only when it has rdf:resource)
                assert($localName !== 'Description' || $this->reader->getAttribute('rdf:resource') === null, 'rdf:Description cannot be used as a property element');
            }

            // rdf:Description block starting
            if ($namespace === self::RDF_NAMESPACE && $localName === 'Description') {
                $descriptionDepth = $this->reader->depth;
                $this->subjectStack->push(['subject' => $this->subject, 'depth' => $descriptionDepth]);

                // Reset li counter when a new Description starts
                // Reset at the depth where rdf:li elements will appear (one level deeper)
                unset($this->liCounters[$descriptionDepth + 1]);

                // Check for deprecated rdf:aboutEach and rdf:aboutEachPrefix attributes
                assert($this->reader->getAttribute('rdf:aboutEach') === null, 'rdf:aboutEach has been removed from RDF specifications');
                assert($this->reader->getAttribute('rdf:aboutEachPrefix') === null, 'rdf:aboutEachPrefix has been removed from RDF specifications');

                // Check for deprecated rdf:bagID attribute
                assert($this->reader->getAttribute('rdf:bagID') === null, 'rdf:bagID has been removed from RDF specifications');

                $about  = $this->reader->getAttribute('rdf:about') ?? $this->reader->getAttribute('about');
                $nodeId = $this->reader->getAttribute('rdf:nodeID');
                $idAttr = $this->reader->getAttribute('rdf:ID');

                // Error: rdf:nodeID and rdf:ID cannot be used together
                assert($nodeId === null || $idAttr === null, 'Cannot have rdf:nodeID and rdf:ID');

                // Error: rdf:nodeID and rdf:about cannot be used together
                assert($nodeId === null || $about === null, 'Cannot have rdf:nodeID and rdf:about');

                $this->subject = $this->resolveSubject($about, $nodeId, $idAttr, true);

                $descriptionLang = $this->reader->xmlLang ?: null;

                // Check for rdf:li used as an attribute on Description (only allowed as element)
                assert($this->reader->getAttribute('rdf:li') === null, 'rdf:li cannot be used as an attribute');

                // Process attributes on Description element as property-value pairs
                if ($this->reader->hasAttributes) {
                    $this->reader->moveToFirstAttribute();
                    do {
                        $attrLocalName = $this->reader->localName;
                        $attrNamespace = $this->reader->namespaceURI;

                        // Skip structural RDF namespace attributes, xml:base, and xmlns declarations
                        // Note: rdf:type is NOT skipped - it's processed as a property attribute
                        $skipRdfAttr = $attrNamespace === self::RDF_NAMESPACE && in_array(
                            $attrLocalName,
                            ['about', 'ID', 'nodeID', 'resource', 'parseType', 'datatype'],
                            true,
                        );
                        if (
                            $skipRdfAttr
                            || $attrNamespace === XMLUtils::XML_NAMESPACE
                            || $attrNamespace === XMLUtils::XMLNS_NAMESPACE
                            || $attrNamespace === ''
                        ) {
                            continue;
                        }

                        $predicate = new Iri($attrNamespace . $attrLocalName);
                        $value     = $this->reader->value;

                        assert($this->subject !== null, 'subject must be set when processing Description attributes');

                        // rdf:type attribute values are URIs, not literals
                        if ($attrNamespace === self::RDF_NAMESPACE && $attrLocalName === 'type') {
                            $resolvedURI = $this->resolveURI($value);
                            $object      = new Iri($resolvedURI);
                        } else {
                            $object = new Literal($value, $descriptionLang);
                        }

                        $this->emit([
                            $this->subject,
                            $predicate,
                            $object,
                            null,
                        ]);
                    } while ($this->reader->moveToNextAttribute());

                    $this->reader->moveToElement();
                }

                // If self-closing, pop stacks immediately
                if ($this->reader->isEmptyElement) {
                    if (! $this->subjectStack->isEmpty() && $this->subjectStack->top()['depth'] === $descriptionDepth) {
                        $popped        = $this->subjectStack->pop();
                        $this->subject = $popped['subject'];
                    }
                }

                continue;
            }

            // Property element (check first, as property elements can have rdf:ID for reification)
            // Also handle rdf:li elements which should be converted to numbered properties
            // RDF container elements (Bag, Seq, Alt, List) can be property elements when there's a subject
            // NOTE: The check for forbidden property element names was moved above, before the rdf:Description check

            $isRdfContainerProperty = $namespace === self::RDF_NAMESPACE && in_array($localName, ['Bag', 'Seq', 'Alt', 'List'], true) && $this->subject !== null;
            if ($this->subject !== null && (($namespace !== '' && $namespace !== self::RDF_NAMESPACE) || ($namespace === self::RDF_NAMESPACE && $localName === 'li') || $isRdfContainerProperty)) {
                // For rdf:li, convert to numbered property
                if ($namespace === self::RDF_NAMESPACE && $localName === 'li') {
                    $predicate = $this->nextLiProperty();
                } else {
                    assert($localName !== '', 'local name must be non-empty');
                    $predicate = new Iri($namespace . $localName);
                }

                // Check for deprecated rdf:bagID attribute on property elements
                assert($this->reader->getAttribute('rdf:bagID') === null, 'rdf:bagID has been removed from RDF specifications');

                $resourceAttr = $this->reader->getAttribute('rdf:resource') ?? $this->reader->getAttribute('resource');
                $nodeIdAttr   = $this->reader->getAttribute('rdf:nodeID');
                $parseType    = $this->reader->getAttribute('rdf:parseType');
                $idAttr       = $this->reader->getAttribute('rdf:ID');
                $datatypeAttr = $this->reader->getAttribute('rdf:datatype') ?? $this->reader->getAttribute('datatype');
                $propertyLang = $this->reader->xmlLang ?: null;

                // Error: rdf:nodeID and rdf:resource cannot be used together
                assert($nodeIdAttr === null || $resourceAttr === null, 'rdf:nodeID and rdf:resource cannot be used together');

                assert($nodeIdAttr === null || self::isValidXmlName($nodeIdAttr), 'rdf:nodeID value must match XML Name production: ' . $nodeIdAttr);

                // Handle rdf:ID on property element (reification)
                $reificationURI = null;
                if ($idAttr !== null) {
                    // Validate rdf:ID value matches XML Name production
                    assert(self::isValidXmlName($idAttr), 'rdf:ID value must match XML Name production: ' . $idAttr);

                    $reificationURI = $this->resolveURI('#' . $idAttr);
                }

                // Handle rdf:parseType="Collection" - create list structure
                if ($parseType === 'Collection') {
                    $this->handleParseTypeCollection($this->subject, $predicate, $reificationURI);
                    continue;
                }

                // Handle rdf:parseType="Resource" - create blank node and process nested content
                if ($parseType === 'Resource') {
                    $this->handleParseTypeResource($this->subject, $predicate, $reificationURI);

                    continue;
                }

                // Handle rdf:parseType="Literal" - read inner XML content and canonicalize
                if ($parseType === 'Literal') {
                    $this->handleParseTypeLiteral($this->subject, $predicate, $reificationURI, $resourceAttr);

                    continue;
                }

                if ($resourceAttr !== null) {
                    $resolvedURI = $this->resolveURI($resourceAttr);
                    $object      = new Iri($resolvedURI);

                    $this->emitTripleWithReification($this->subject, $predicate, $object, $reificationURI);

                    // Process non-RDF attributes as properties of the object
                    $this->processPropertyElementAttributes($object, $propertyLang);

                    continue;
                }

                if ($nodeIdAttr !== null) {
                    $object = $this->makeBlankNode($nodeIdAttr);
                    $this->emitTripleWithReification($this->subject, $predicate, $object, $reificationURI);

                    continue;
                }

                // Check for non-RDF attributes on property element
                $hasNonRdfAttributes = false;
                if ($this->reader->hasAttributes) {
                    $this->reader->moveToFirstAttribute();
                    do {
                        $attrNamespace = $this->reader->namespaceURI;
                        $attrLocalName = $this->reader->localName;
                        // Check if it's a non-RDF, non-XML attribute
                        if (
                            $attrNamespace !== self::RDF_NAMESPACE
                            && $attrNamespace !== XMLUtils::XML_NAMESPACE
                            && $attrNamespace !== XMLUtils::XMLNS_NAMESPACE
                            && $attrNamespace !== ''
                            && ! in_array($attrLocalName, ['resource', 'ID', 'nodeID', 'parseType', 'datatype'], true)
                        ) {
                            $hasNonRdfAttributes = true;
                            break;
                        }
                    } while ($this->reader->moveToNextAttribute());

                    $this->reader->moveToElement();
                }

                // If there are non-RDF attributes and no rdf:resource/rdf:nodeID, create blank node object
                if ($hasNonRdfAttributes) {
                    $object = $this->makeBlankNode(null);
                    $this->emitTripleWithReification($this->subject, $predicate, $object, $reificationURI);

                    // Process non-RDF attributes as properties of the object
                    $this->processPropertyElementAttributes($object, $propertyLang);

                    continue;
                }

                // Check if element is empty (self-closing)
                if ($this->reader->isEmptyElement) {
                    // Empty property element creates empty string literal
                    $object = new Literal('', $propertyLang);
                    $this->emitTripleWithReification($this->subject, $predicate, $object, $reificationURI);

                    continue;
                }

                // Read text content
                $this->reader->read();
                if (
                    $this->reader->nodeType !== XMLReader::TEXT &&
                    $this->reader->nodeType !== XMLReader::CDATA
                ) {
                    // Check if we're at the end of the element (empty content)
                    if ($this->reader->nodeType === XMLReader::END_ELEMENT) {
                        // Empty property element creates empty string literal
                        $object = new Literal('', $propertyLang);
                        $this->emitTripleWithReification($this->subject, $predicate, $object, $reificationURI);

                        continue;
                    }

                    // Nested content - store property info to emit when nested element closes
                    // This handles both cases: with and without rdf:ID
                    $this->pendingProperty = [
                        'subject' => $this->subject,
                        'predicate' => $predicate,
                        'depth' => $this->reader->depth,
                        'reificationURI' => $reificationURI,
                    ];

                    continue;
                }

                $object = $datatypeAttr !== null
                    ? new Literal($this->reader->value, null, $this->resolveURI($datatypeAttr))
                    : new Literal($this->reader->value, $propertyLang);

                $this->emitTripleWithReification($this->subject, $predicate, $object, $reificationURI);

                continue;
            }

            // Typed node (e.g. <ex:Book rdf:about="..."> or <rdf:Property rdf:about="...">)
            // Also includes typed nodes without node-identifying attributes (creates blank node)
            // Must check after property elements, as property elements can have rdf:ID for reification
            // Check for deprecated rdf:aboutEach and rdf:aboutEachPrefix attributes on typed nodes
            assert($this->reader->getAttribute('rdf:aboutEach') === null, 'rdf:aboutEach has been removed from RDF specifications');
            assert($this->reader->getAttribute('rdf:aboutEachPrefix') === null, 'rdf:aboutEachPrefix has been removed from RDF specifications');
            // Check for rdf:li used as an attribute (only allowed as element)
            assert($this->reader->getAttribute('rdf:li') === null, 'rdf:li cannot be used as an attribute');

            // Check for deprecated rdf:bagID attribute on typed nodes
            assert($this->reader->getAttribute('rdf:bagID') === null, 'rdf:bagID has been removed from RDF specifications');

            $about         = $this->reader->getAttribute('rdf:about') ?? $this->reader->getAttribute('about');
            $nodeId        = $this->reader->getAttribute('rdf:nodeID');
            $idAttr        = $this->reader->getAttribute('rdf:ID');
            $isNodeElement = $about !== null || $nodeId !== null || ($idAttr !== null && $this->subject === null);

            // RDF container elements (Bag, Seq, Alt, List) are typed nodes only when there's no subject (top-level)
            $isRdfContainer = $namespace === self::RDF_NAMESPACE && in_array($localName, ['Bag', 'Seq', 'Alt', 'List'], true) && $this->subject === null;
            // Typed node: non-RDF namespace element OR RDF container element (only when no subject), but not RDF or Description
            $isTypedNode = $localName !== 'RDF' && ($namespace !== self::RDF_NAMESPACE || ($isRdfContainer && $this->subject === null) || $localName !== 'Description') && ($isNodeElement || ($namespace !== self::RDF_NAMESPACE && $this->subject === null) || $isRdfContainer);

            if ($isTypedNode) {
                $typedNodeDepth = $this->reader->depth;

                // Check for duplicate rdf:ID values on typed nodes (before resolving)
                if ($idAttr !== null) {
                    // Validate rdf:ID value matches XML Name production
                    assert(self::isValidXmlName($idAttr), 'rdf:ID value must match XML Name production: ' . $idAttr);

                    $resolvedURI = $this->resolveURI('#' . $idAttr);
                    assert(! $this->sawIdentifier($resolvedURI), 'Duplicate rdf:ID value: ' . $idAttr);
                }

                $subject = $this->resolveSubject($about, $nodeId, $idAttr, false);

                $this->subjectStack->push(['subject' => $this->subject, 'depth' => $typedNodeDepth]);
                $this->subject = $subject;

                $object = $namespace . $localName;
                assert($object !== '', 'object may not be empty');

                $this->emit([
                    $subject,
                    new Iri(self::RDF_NAMESPACE . 'type'),
                    new Iri($object),
                    null,
                ]);

                // Process attributes on typed node element as property-value pairs
                // This handles attributes like rdf:_3, rdf:value on container elements
                $typedNodeLang = $this->reader->xmlLang ?: null;
                if ($this->reader->hasAttributes) {
                    $this->reader->moveToFirstAttribute();
                    do {
                        $attrLocalName = $this->reader->localName;
                        $attrNamespace = $this->reader->namespaceURI;

                        // Skip structural RDF namespace attributes, xml:base, and xmlns declarations
                        // Note: rdf:type is NOT skipped - it's processed as a property attribute
                        // Also, numbered properties (rdf:_1, rdf:_2, etc.) and rdf:value are NOT skipped
                        $skipRdfAttr = $attrNamespace === self::RDF_NAMESPACE && in_array(
                            $attrLocalName,
                            ['about', 'ID', 'nodeID', 'resource', 'parseType', 'datatype'],
                            true,
                        );
                        if (
                            $skipRdfAttr
                            || $attrNamespace === XMLUtils::XML_NAMESPACE
                            || $attrNamespace === XMLUtils::XMLNS_NAMESPACE
                            || $attrNamespace === ''
                        ) {
                            continue;
                        }

                        $predicate = new Iri($attrNamespace . $attrLocalName);
                        $value     = $this->reader->value;

                        $this->emit([
                            $subject,
                            $predicate,
                            new Literal($value, $typedNodeLang),
                            null,
                        ]);
                    } while ($this->reader->moveToNextAttribute());

                    $this->reader->moveToElement();
                }

                // If self-closing, pop stacks immediately
                if ($this->reader->isEmptyElement) {
                    // we know the subject stack is non-empty because we pushed to it above!
                    if (! $this->subjectStack->isEmpty() && $this->subjectStack->top()['depth'] === $typedNodeDepth) {
                        $popped        = $this->subjectStack->pop();
                        $this->subject = $popped['subject'];
                    }
                }

                continue;
            }

            $iri = $namespace . $localName;
            assert($iri !== '', 'iri must be non-empty');
            $predicate    = new Iri($iri);
            $resourceAttr = $this->reader->getAttribute('rdf:resource') ?? $this->reader->getAttribute('resource');
            $nodeIdAttr   = $this->reader->getAttribute('rdf:nodeID');
            $parseType    = $this->reader->getAttribute('rdf:parseType');
            $idAttr       = $this->reader->getAttribute('rdf:ID');
            $datatypeAttr = $this->reader->getAttribute('rdf:datatype') ?? $this->reader->getAttribute('datatype');
            $elementLang  = $this->reader->xmlLang ?: null;

            // Handle rdf:ID on property element (reification)
            $reificationURI = null;
            if ($idAttr !== null) {
                $reificationURI = $this->resolveURI('#' . $idAttr);
            }

            // Handle rdf:parseType="Collection" - create list structure
            if ($parseType === 'Collection') {
                assert($this->subject !== null, 'subject must be set for collection');
                $this->handleParseTypeCollection($this->subject, $predicate, $reificationURI);

                continue;
            }

            // Handle rdf:parseType="Resource" - create blank node and process nested content
            if ($parseType === 'Resource') {
                assert($this->subject !== null, 'subject must be set for resource');
                $this->handleParseTypeResource($this->subject, $predicate, $reificationURI);

                continue;
            }

            // Handle rdf:parseType="Literal" - read inner XML content and canonicalize
            if ($parseType === 'Literal') {
                assert($this->subject !== null, 'subject must be set for literal');
                $this->handleParseTypeLiteral($this->subject, $predicate, $reificationURI, $resourceAttr);

                continue;
            }

            if ($resourceAttr !== null) {
                assert($this->subject !== null, 'subject must be set');
                $resolvedURI = $this->resolveURI($resourceAttr);
                $object      = new Iri($resolvedURI);

                $this->emitTripleWithReification($this->subject, $predicate, $object, $reificationURI);

                // Process non-RDF attributes as properties of the object
                $this->processPropertyElementAttributes($object, $elementLang);

                continue;
            }

            if ($nodeIdAttr !== null) {
                assert($this->subject !== null, 'subject must be set');
                $object = $this->makeBlankNode($nodeIdAttr);
                $this->emitTripleWithReification($this->subject, $predicate, $object, $reificationURI);

                continue;
            }

            // Check for non-RDF attributes on property element
            $hasNonRdfAttributes = false;
            if ($this->reader->hasAttributes) {
                $this->reader->moveToFirstAttribute();
                do {
                    $attrNamespace = $this->reader->namespaceURI;
                    $attrLocalName = $this->reader->localName;
                    // Check if it's a non-RDF, non-XML attribute
                    if (
                        $attrNamespace !== self::RDF_NAMESPACE
                        && $attrNamespace !== XMLUtils::XML_NAMESPACE
                        && $attrNamespace !== XMLUtils::XMLNS_NAMESPACE
                        && $attrNamespace !== ''
                        && ! in_array($attrLocalName, ['resource', 'ID', 'nodeID', 'parseType', 'datatype'], true)
                    ) {
                        $hasNonRdfAttributes = true;
                        break;
                    }
                } while ($this->reader->moveToNextAttribute());

                $this->reader->moveToElement();
            }

            // If there are non-RDF attributes and no rdf:resource/rdf:nodeID, create blank node object
            if ($hasNonRdfAttributes) {
                assert($this->subject !== null, 'subject must be set');
                $object = $this->makeBlankNode(null);
                $this->emitTripleWithReification($this->subject, $predicate, $object, $reificationURI);

                // Process non-RDF attributes as properties of the object
                $this->processPropertyElementAttributes($object, $elementLang);

                continue;
            }

            // Check if element is empty (self-closing)
            if ($this->reader->isEmptyElement) {
                assert($this->subject !== null, 'subject must be set');
                // Empty property element creates empty string literal
                $object = new Literal('', $elementLang);
                $this->emitTripleWithReification($this->subject, $predicate, $object, $reificationURI);

                continue;
            }

            // Read text content
            $this->reader->read();
            if (
                $this->reader->nodeType !== XMLReader::TEXT &&
                $this->reader->nodeType !== XMLReader::CDATA
            ) {
                // Check if we're at the end of the element (empty content)
                if ($this->reader->nodeType === XMLReader::END_ELEMENT) {
                    assert($this->subject !== null, 'subject must be set');
                    // Empty property element creates empty string literal
                    $object = new Literal('', $elementLang);
                    $this->emitTripleWithReification($this->subject, $predicate, $object, $reificationURI);

                    continue;
                }

                // Nested content - store property info to emit when nested element closes
                // This handles both cases: with and without rdf:ID
                if ($this->subject !== null) {
                    $this->pendingProperty = [
                        'subject' => $this->subject,
                        'predicate' => $predicate,
                        'depth' => $this->reader->depth,
                        'reificationURI' => $reificationURI,
                    ];
                }

                continue;
            }

            assert($this->subject !== null, 'subject must be set');
            $object = $datatypeAttr !== null
                ? new Literal($this->reader->value, null, $this->resolveURI($datatypeAttr))
                : new Literal($this->reader->value, $elementLang);

            $this->emitTripleWithReification($this->subject, $predicate, $object, $reificationURI);
        }
    }

    /**
     * Check if a string matches the XML Name production.
     * XML Name: NameStartChar (NameChar)*
     * NameStartChar: ":" | [A-Z] | "_" | [a-z] | [#xC0-#xD6] | [#xD8-#xF6] | [#xF8-#x2FF] | [#x370-#x37D] | [#x37F-#x1FFF] | [#x200C-#x200D] | [#x2070-#x218F] | [#x2C00-#x2FEF] | [#x3001-#xD7FF] | [#xF900-#xFDCF] | [#xFDF0-#xFFFD] | [#x10000-#xEFFFF]
     * NameChar: NameStartChar | "-" | "." | [0-9] | #xB7 | [#x0300-#x036F] | [#x203F-#x2040]
     *
     * For rdf:ID and rdf:nodeID, we disallow colons (they're not namespace-qualified).
     * Use XMLReader's built-in validation by checking if the attribute value would be valid as an XML name.
     * Simplified check: must not start with digit, must not contain colon, and must be valid UTF-8.
     *
     * @phpstan-assert non-empty-string $name
     */
    private static function isValidXmlName(string $name): bool
    {
        if ($name === '') {
            return false;
        }

        // For rdf:ID and rdf:nodeID, disallow colons (they're not namespace-qualified)
        if (str_contains($name, ':')) {
            return false;
        }

        // Check first character: must not be a digit
        $first = mb_substr($name, 0, 1, 'UTF-8');
        if ($first >= '0' && $first <= '9') {
            return false;
        }

        // Use a simple regex to check if it's a valid XML name
        // Allow letters, digits, underscores, hyphens, periods, and Unicode characters
        // This is a simplified check - for full XML Name validation, we'd need more complex logic
        return (bool) preg_match('/^[\p{L}_][\p{L}\p{N}_\\.\\-]*$/u', $name);
    }
}
