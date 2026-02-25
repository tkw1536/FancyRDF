<?php

declare(strict_types=1);

namespace FancyRDF\Formats;

use FancyRDF\Dataset\Quad;
use FancyRDF\Formats\TrigReader\TrigReader;
use FancyRDF\Formats\TrigReader\TrigToken;
use FancyRDF\Term\BlankNode;
use FancyRDF\Term\Iri;
use FancyRDF\Term\Literal;
use FancyRDF\Uri\UriReference;
use Override;

use function assert;
use function in_array;
use function is_string;
use function preg_match;
use function strpos;
use function substr;

/**
 * Parser for Turtle and TriG format.
 *
 * Consumes tokens from TrigReader and yields triple or quad arrays.
 * Turtle mode emits triples [s, p, o, null]; TriG mode emits quads [s, p, o, graph].
 *
 * @see https://www.w3.org/TR/turtle/
 * @see https://www.w3.org/TR/trig/
 *
 * @phpstan-import-type TripleOrQuadArray from Quad
 * @extends FiberIterator<TripleOrQuadArray>
 */
final class TrigParser extends FiberIterator
{
    private const string RDF_NAMESPACE = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';
    private const string XSD_NAMESPACE = 'http://www.w3.org/2001/XMLSchema#';

    /** @var array<string, string> */
    private array $namespaces = [];

    private Iri|BlankNode|null $curSubject = null;

    private Iri|null $curPredicate = null;

    /** @var Iri|BlankNode|null (TriG only) */
    private Iri|BlankNode|null $curGraph = null;

    public function __construct(
        private readonly TrigReader $reader,
        public readonly bool $isTrig = false,
        private string $base = '',
    ) {
    }

    #[Override]
    protected function doIterate(): void
    {
        while ($this->reader->next()) {
            $type = $this->reader->getTokenType();
            if ($type === TrigToken::EndOfInput) {
                break;
            }

            $atVal = $type === TrigToken::AtKeyword ? $this->reader->getTokenValue() : null;
            if ($type === TrigToken::AtKeyword && $atVal === 'prefix' || $type === TrigToken::Prefix) {
                $this->parsePrefixDirective($type === TrigToken::AtKeyword);
                continue;
            }

            if ($type === TrigToken::AtKeyword && $atVal === 'base' || $type === TrigToken::Base) {
                $this->parseBaseDirective($type === TrigToken::AtKeyword);
                continue;
            }

            match ($type) {
                TrigToken::LCurly => $this->parseWrappedGraphDefault(),
                TrigToken::Graph => $this->parseGraphKeywordBlock(),
                default => $this->parseTriplesOrGraphBlock($type),
            };
        }
    }

    /** @param bool $isAtDirective true for @prefix (expect DOT after), false for PREFIX */
    private function parsePrefixDirective(bool $isAtDirective): void
    {
        $this->reader->next();
        $type = $this->reader->getTokenType();
        assert($type === TrigToken::PnameNs, 'expected PNAME_NS after \'@prefix\'');
        $name = $this->reader->getTokenValue();

        $this->reader->next();
        $type = $this->reader->getTokenType();
        assert($type === TrigToken::IriRef, 'expected IRIREF after PNAME_NS');
        $iriRef = $this->reader->getTokenValue();

        $iri                     = $this->resolveIriRef($iriRef);
        $this->namespaces[$name] = $iri;

        if (! $isAtDirective) {
            return;
        }

        $this->reader->next();
        assert($this->reader->getTokenType() === TrigToken::Dot, 'expected \'.\' after @prefix');
    }

    /** @param bool $isAtDirective true for @base (expect DOT after), false for BASE */
    private function parseBaseDirective(bool $isAtDirective): void
    {
        $this->reader->next();
        $type = $this->reader->getTokenType();
        assert($type === TrigToken::IriRef, 'expected IRIREF after \'@base\'');

        $iriRef     = $this->reader->getTokenValue();
        $this->base = $this->resolveIriRef($iriRef);

        if (! $isAtDirective) {
            return;
        }

        $hasNext = $this->reader->next();
        assert($hasNext, 'expected DOT after @base');
        assert($this->reader->getTokenType() === TrigToken::Dot, 'expected DOT');
    }

    private function parseWrappedGraphDefault(): void
    {
        assert($this->isTrig, 'UNEXPECTED \'{\' in Turtle mode');

        $this->curGraph = null;
        $this->parseTriplesBlock();
    }

    private function parseGraphKeywordBlock(): void
    {
        assert($this->isTrig, 'GRAPH keyword not allowed in Turtle mode');

        $this->reader->next();
        $type  = $this->reader->getTokenType();
        $label = $this->parseGraphLabel($type);

        $this->curGraph = $label;

        $this->reader->next();
        assert($this->reader->getTokenType() === TrigToken::LCurly, 'expected {');

        $this->parseTriplesBlock();
    }

    /**
     * Parses a graph label (IRI, blank node, or []). No collection () allowed.
     *
     * @return Iri|BlankNode|null null only for invalid/unknown token (caller must assert).
     */
    private function parseGraphLabel(TrigToken $type): Iri|BlankNode|null
    {
        return match ($type) {
            TrigToken::IriRef => $this->parseIriRefTerm(),
            TrigToken::PnameLn, TrigToken::PnameNs => $this->parsePnameTerm(),
            TrigToken::BlankNodeLabel => $this->parseBlankNodeLabel(),
            TrigToken::LSquare => $this->parseGraphLabelEmptyBnodePropertyList(),
            default => $this->parseGraphLabelDefault($type),
        };
    }

    private function parseGraphLabelDefault(TrigToken $type): null
    {
        assert($type !== TrigToken::EndOfInput, 'expected label after GRAPH');

        return null;
    }

    /** Graph label allows only [] (empty blank node property list), not [ p o ]. */
    private function parseGraphLabelEmptyBnodePropertyList(): BlankNode
    {
        $label = $this->makeBlankNode(null);
        $this->reader->next();
        assert($this->reader->getTokenType() === TrigToken::RSquare, 'expected ] after [');

        return $label;
    }

    private function canStartGraphBlock(TrigToken $type): bool
    {
        return $this->isTrig && in_array($type, [
            TrigToken::IriRef,
            TrigToken::PnameLn,
            TrigToken::PnameNs,
            TrigToken::BlankNodeLabel,
        ], true);
    }

    private function parseTriplesOrGraphBlock(TrigToken $type): void
    {
        $this->curGraph = null;

        $subject          = $this->parseSubject($type);
        $this->curSubject = $subject;

        $allowSoleSubject = $type === TrigToken::LSquare;

        $this->reader->next();
        $verbType = $this->reader->getTokenType();
        if ($verbType !== TrigToken::LCurly) {
            $this->parsePredicateObjectList($verbType, null, $allowSoleSubject);

            return;
        }

        $mayBeGraphBlock = $this->canStartGraphBlock($type);
        assert($mayBeGraphBlock || $allowSoleSubject, 'expected verb');
        assert($mayBeGraphBlock || $this->lastBlankNodePropertyListWasEmpty, 'blank node property list not allowed as graph label');
        $this->curGraph = $subject;

        $this->parseTriplesBlock();
    }

    private function parseTriplesBlock(): void
    {
        while (
            $this->reader->getTokenType() !== TrigToken::RCurly &&
            $this->reader->next()
        ) {
            $type = $this->reader->getTokenType();
            if ($type === TrigToken::RCurly) {
                break;
            }

            assert($type !== TrigToken::Dot, 'unexpected .');

            $this->curSubject = $this->parseSubject($type);
            $allowSoleSubject = $type === TrigToken::LSquare;

            $this->reader->next();
            $type = $this->reader->getTokenType();
            assert($type !== TrigToken::RCurly || $allowSoleSubject, 'expected verb or }');
            if ($type === TrigToken::RCurly) {
                break;
            }

            $this->parsePredicateObjectList($type, TrigToken::RCurly, $allowSoleSubject);
        }
    }

    private function isPredicateObjectListTerminator(TrigToken $verbType, TrigToken|null $endToken, bool $allowSoleSubject, bool $hasPredicate): bool
    {
        $isTerminator = $verbType === TrigToken::Dot
            || ($endToken !== null && $verbType === $endToken)
            || $verbType === TrigToken::LCurly
            || $verbType === TrigToken::RSquare
            || $verbType === TrigToken::EndOfInput;

        if ($isTerminator) {
            assert($hasPredicate || $allowSoleSubject || $verbType !== TrigToken::Dot, 'expected verb');
        }

        return $isTerminator;
    }

    private function parsePredicateObjectList(TrigToken $verbType, TrigToken|null $endToken = null, bool $allowSoleSubject = false): void
    {
        $hasPredicate = false;
        while (true) {
            while ($verbType === TrigToken::Semicolon) {
                $hasNext = $this->reader->next();
                assert($hasNext, 'expected verb or .' . ($endToken !== null ? ' or ' . $endToken->value : ''));
                $verbType = $this->reader->getTokenType();
            }

            if ($this->isPredicateObjectListTerminator($verbType, $endToken, $allowSoleSubject, $hasPredicate)) {
                break;
            }

            $predicate          = $this->parseVerb($verbType);
            $this->curPredicate = $predicate;

            $hasNext = $this->reader->next();
            assert($hasNext, 'expected object after verb');

            $this->parseObjectList();
            $hasPredicate = true;

            $next = $this->reader->getTokenType();
            if ($next === TrigToken::Dot) {
                break;
            }

            if ($endToken !== null && $next === $endToken) {
                break;
            }

            assert($next === TrigToken::Semicolon, 'expected ;');

            $verbType = TrigToken::Semicolon;
            $hasNext  = $this->reader->next();
            assert($hasNext, 'expected verb or object after ;');
            $verbType = $this->reader->getTokenType();
        }
    }

    private function parseObjectList(): void
    {
        while (true) {
            $object = $this->parseObject();

            assert($this->curSubject !== null && $this->curPredicate !== null, 'subject and predicate must be set');
            $graph = $this->isTrig ? $this->curGraph : null;
            $this->emit([$this->curSubject, $this->curPredicate, $object, $graph]);

            if ($this->reader->getTokenType() !== TrigToken::Comma) {
                break;
            }

            $this->reader->next();
            assert($this->reader->getTokenType() !== TrigToken::EndOfInput, 'expected object after ,');
        }
    }

    private function neverReachedFallback(string $expected, TrigToken $got): BlankNode
    {
        /** @phpstan-ignore function.impossibleType (GIGO) */
        assert(false, 'expected ' . $expected . ', got ' . $got->value);

        return new BlankNode('gigo');
    }

    private function parseSubject(TrigToken $type): Iri|BlankNode
    {
        return match ($type) {
            TrigToken::IriRef => $this->parseIriRefTerm(),
            TrigToken::PnameLn, TrigToken::PnameNs => $this->parsePnameTerm(),
            TrigToken::BlankNodeLabel => $this->parseBlankNodeLabel(),
            TrigToken::LSquare => $this->parseBlankNodePropertyListSubject(),
            TrigToken::LParen => $this->parseCollection(false),
            default => $this->neverReachedFallback('subject', $type),
        };
    }

    private function parseVerb(TrigToken $type): Iri
    {
        if ($type === TrigToken::A) {
            return new Iri(self::RDF_NAMESPACE . 'type');
        }

        assert($type === TrigToken::IriRef || $type === TrigToken::PnameLn || $type === TrigToken::PnameNs, 'expected predicate (iri or a)');

        return $this->parseIriTerm($type);
    }

    private function parseObject(): Iri|BlankNode|Literal
    {
        $type = $this->reader->getTokenType();

        $result = match ($type) {
            TrigToken::IriRef => $this->parseIriRefTerm(),
            TrigToken::PnameLn, TrigToken::PnameNs => $this->parsePnameTerm(),
            TrigToken::BlankNodeLabel => $this->parseBlankNodeLabel(),
            TrigToken::LSquare => $this->parseBlankNodePropertyListObject(),
            TrigToken::LParen => $this->parseCollection(),
            TrigToken::String => $this->parseLiteral(),
            TrigToken::Integer, TrigToken::Decimal, TrigToken::Double => $this->parseNumericLiteral(),
            TrigToken::True, TrigToken::False => $this->parseBooleanLiteral(),
            default => $this->neverReachedFallback('object', $type),
        };

        // Only advance when the item parser did not already consume the next token.
        // parseLiteral(), parseBlankNodePropertyListObject(), parseCollectionElement() advance the reader.
        if ($type === TrigToken::String || $type === TrigToken::LSquare || $type === TrigToken::LParen) {
            return $result;
        }

        $this->reader->next();

        return $result;
    }

    private function parseIriFromToken(TrigToken $type): Iri
    {
        $value    = $this->reader->getTokenValue();
        $resolved = $type === TrigToken::IriRef
            ? $this->resolveIriRef($value)
            : $this->expandPname($type, $value);

        return new Iri($resolved);
    }

    private function parseIriRefTerm(): Iri
    {
        return $this->parseIriFromToken(TrigToken::IriRef);
    }

    private function parseIriTerm(TrigToken $type): Iri
    {
        return $this->parseIriFromToken($type);
    }

    private function parsePnameTerm(): Iri|BlankNode
    {
        $type  = $this->reader->getTokenType();
        $value = $this->reader->getTokenValue();
        if ($type === TrigToken::BlankNodeLabel) {
            assert($value !== '', 'BLANK_NODE_LABEL is empty');

            return $this->makeBlankNode($value);
        }

        $resolved = $this->expandPname($type, $value);

        return new Iri($resolved);
    }

    /** @return non-empty-string */
    private function resolveIriRef(string $decodedIri): string
    {
        assert(preg_match('/[\x00-\x20<>"{}|^`\\\\]/u', $decodedIri) !== 1, 'IRIREF contains disallowed character');

        $iri = $this->base !== '' ? UriReference::resolveRelative($this->base, $decodedIri) : $decodedIri;
        assert($iri !== '', 'resolved IRI is empty');

        return $iri;
    }

    /** @return non-empty-string */
    private function expandPname(TrigToken $type, string $tokenValue): string
    {
        assert($type === TrigToken::PnameLn || $type === TrigToken::PnameNs, 'expected PNAME');
        $colonPos = strpos($tokenValue, ':');
        assert($colonPos !== false, 'PNAME must contain :');
        $prefix = substr($tokenValue, 0, $colonPos + 1);
        $local  = substr($tokenValue, $colonPos + 1);

        $ns = $this->namespaces[$prefix] ?? null;
        assert($ns !== null, 'undefined prefix: ' . $prefix);

        $full = $ns . $local;

        if ($this->base !== '') {
            $full = UriReference::resolveRelative($this->base, $full);
        }

        assert($full !== '', 'expanded IRI is empty');

        return $full;
    }

    private function parseBlankNodeLabel(): BlankNode
    {
        $label = $this->reader->getTokenValue();
        assert($label !== '', 'BLANK_NODE_LABEL is empty');

        return $this->makeBlankNode($label);
    }

    /**
     * Parse a blank node property list: [ predicateObjectList ].
     *
     * @param bool $consumeClosingBracket if true, consume the closing ] so callers are on the next token;
     *                                     if false, leave reader on ] so caller can call next() to advance.
     */
    private function parseBlankNodePropertyList(bool $consumeClosingBracket): BlankNode
    {
        $bnode            = $this->makeBlankNode(null);
        $savedSubject     = $this->curSubject;
        $savedPredicate   = $this->curPredicate;
        $this->curSubject = $bnode;

        $hasNext = $this->reader->next();
        assert($hasNext, 'expected predicateObjectList or ]');
        $type = $this->reader->getTokenType();

        $this->lastBlankNodePropertyListWasEmpty = $type === TrigToken::RSquare;
        if ($type !== TrigToken::RSquare) {
            $this->parsePredicateObjectList($type, TrigToken::RSquare);
        }

        if ($consumeClosingBracket) {
            assert($this->reader->getTokenType() === TrigToken::RSquare, 'expected ] after blank node property list');
            $this->reader->next();
        }

        $this->curSubject   = $savedSubject;
        $this->curPredicate = $savedPredicate;

        return $bnode;
    }

    /**
     * Parse a blank node used as subject: [ predicateObjectList ].
     * Leaves the reader on the closing ] so that the caller (e.g. parseTriples) can
     * call next() to advance to the verb.
     */
    private function parseBlankNodePropertyListSubject(): BlankNode
    {
        return $this->parseBlankNodePropertyList(false);
    }

    /**
     * Parse a blank node used as object: [ predicateObjectList ].
     * Consumes the closing ] so that callers are positioned on the next token.
     */
    private function parseBlankNodePropertyListObject(): BlankNode
    {
        return $this->parseBlankNodePropertyList(true);
    }

    private function parseCollection(bool $consumeFinalToken = true): Iri|BlankNode
    {
        if (! $this->reader->next() || $this->reader->getTokenType() === TrigToken::RParen) {
            if ($consumeFinalToken) {
                $this->reader->next();
            }

            return new Iri(self::RDF_NAMESPACE . 'nil');
        }

        $objects = [];
        while (true) {
            $type = $this->reader->getTokenType();
            if ($type === TrigToken::RParen || $type === TrigToken::Dot) {
                break;
            }

            $objects[] = $this->parseObject();
        }

        if ($consumeFinalToken) {
            $this->reader->next();
        }

        if ($objects === []) {
            return new Iri(self::RDF_NAMESPACE . 'nil');
        }

        $rdfFirst = new Iri(self::RDF_NAMESPACE . 'first');
        $rdfRest  = new Iri(self::RDF_NAMESPACE . 'rest');
        $rdfNil   = new Iri(self::RDF_NAMESPACE . 'nil');
        $graph    = $this->isTrig ? $this->curGraph : null;

        $headNode    = null;
        $currentNode = null;
        foreach ($objects as $item) {
            $listNode = $this->makeBlankNode(null);
            if ($headNode === null) {
                $headNode = $listNode;
            }

            if ($currentNode !== null) {
                $this->emit([$currentNode, $rdfRest, $listNode, $graph]);
            }

            $this->emit([$listNode, $rdfFirst, $item, $graph]);
            $currentNode = $listNode;
        }

        $this->emit([$currentNode, $rdfRest, $rdfNil, $graph]);

        return $headNode;
    }

    private function parseLiteral(): Literal
    {
        $lexical  = $this->reader->getTokenValue();
        $lang     = null;
        $datatype = null;

        if ($this->reader->next()) {
            if ($this->reader->getTokenType() === TrigToken::AtKeyword) {
                $lang = $this->reader->getTokenValue();
                assert($lang !== '', 'LANGTAG is empty');
                $this->reader->next();
            } elseif ($this->reader->getTokenType() === TrigToken::HatHat) {
                $hasNext = $this->reader->next();
                assert($hasNext, 'expected iri after ^^');
                $type = $this->reader->getTokenType();
                assert($type === TrigToken::IriRef || $type === TrigToken::PnameLn || $type === TrigToken::PnameNs, 'expected datatype iri');
                $datatype = $this->parseIriFromToken($type)->iri;
                $this->reader->next();
            }
        }

        return new Literal($lexical, $lang, $datatype);
    }

    private function parseNumericLiteral(): Literal
    {
        $type     = $this->reader->getTokenType();
        $value    = $this->reader->getTokenValue();
        $datatype = match ($type) {
            TrigToken::Integer => self::XSD_NAMESPACE . 'integer',
            TrigToken::Decimal => self::XSD_NAMESPACE . 'decimal',
            TrigToken::Double => self::XSD_NAMESPACE . 'double',
            default => self::XSD_NAMESPACE . 'decimal',
        };

        return new Literal($value, null, $datatype);
    }

    private function parseBooleanLiteral(): Literal
    {
        return new Literal(
            $this->reader->getTokenValue(),
            null,
            self::XSD_NAMESPACE . 'boolean',
        );
    }

    // ===========================
    // Blank node handling
    // ===========================

    private int $blankNodeCounter = 0;

    private bool $lastBlankNodePropertyListWasEmpty = true;

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
}
