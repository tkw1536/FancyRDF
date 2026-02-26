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
    use BlankNodeGenerator;

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
            match (true) {
                $type === TrigToken::Prefix, $type === TrigToken::AtKeyword && $atVal === 'prefix' =>
                    $this->parsePrefixDirective($type === TrigToken::AtKeyword),
                $type === TrigToken::Base, $type === TrigToken::AtKeyword && $atVal === 'base' =>
                    $this->parseBaseDirective($type === TrigToken::AtKeyword),
                $type === TrigToken::LCurly => $this->parseWrappedGraphDefault(),
                $type === TrigToken::Graph => $this->parseGraphKeywordBlock(),
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
            TrigToken::IriRef => $this->parseIriTerm(),
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
        $label = $this->blankNode(null);
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

    private bool $lastBlankNodePropertyListWasEmpty = true;

    private function parseTriplesOrGraphBlock(TrigToken $type): void
    {
        $maybeGraphBlock = $this->canStartGraphBlock($type);
        $this->curGraph  = null;

        $subject          = $this->parseSubject($type);
        $this->curSubject = $subject;

        $allowSoleSubject = $type === TrigToken::LSquare;

        $this->reader->next();
        $verbType = $this->reader->getTokenType();
        if ($verbType !== TrigToken::LCurly) {
            $this->parsePredicateObjectList(null, $allowSoleSubject);

            return;
        }

        assert(
            $maybeGraphBlock || (
                $allowSoleSubject && $this->lastBlankNodePropertyListWasEmpty
            ),
            'blank node property list not allowed as graph label',
        );
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

            $this->parsePredicateObjectList(TrigToken::RCurly, $allowSoleSubject);
        }
    }

    private function isPredicateObjectListTerminator(TrigToken $type, TrigToken|null $endToken): bool
    {
        return $type === TrigToken::Dot
            || $type === TrigToken::LCurly
            || $type === TrigToken::RSquare
            || $type === $endToken
            || $type === TrigToken::EndOfInput;
    }

    private function parsePredicateObjectList(TrigToken|null $endToken = null, bool $allowSoleSubject = false): void
    {
        $type = $this->reader->getTokenType();

        $hasPredicate = false;
        while (true) {
            while ($type === TrigToken::Semicolon) {
                $this->reader->next();
                $type = $this->reader->getTokenType();

                assert($type !== TrigToken::EndOfInput, 'expected verb or .' . ($endToken !== null ? ' or ' . $endToken->value : ''));
            }

            if ($this->isPredicateObjectListTerminator($type, $endToken)) {
                assert($hasPredicate || $allowSoleSubject || $type !== TrigToken::Dot, 'expected verb');
                break;
            }

            $predicate          = $this->parseVerb();
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

            $type    = TrigToken::Semicolon;
            $hasNext = $this->reader->next();
            assert($hasNext, 'expected verb or object after ;');
            $type = $this->reader->getTokenType();
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
            TrigToken::IriRef => $this->parseIriTerm(),
            TrigToken::PnameLn, TrigToken::PnameNs => $this->parsePnameTerm(),
            TrigToken::BlankNodeLabel => $this->parseBlankNodeLabel(),
            TrigToken::LSquare => $this->parseBlankNodePropertyList(),
            TrigToken::LParen => $this->parseCollection(),
            default => $this->neverReachedFallback('subject', $type),
        };
    }

    private function parseVerb(): Iri
    {
        $type = $this->reader->getTokenType();
        if ($type === TrigToken::A) {
            return new Iri(self::RDF_NAMESPACE . 'type');
        }

        assert($type === TrigToken::IriRef || $type === TrigToken::PnameLn || $type === TrigToken::PnameNs, 'expected predicate (iri or a)');

        return $this->parseIriTerm();
    }

    private function parseObject(): Iri|BlankNode|Literal
    {
        $type = $this->reader->getTokenType();

        $result = match ($type) {
            TrigToken::IriRef => $this->parseIriTerm(),
            TrigToken::PnameLn, TrigToken::PnameNs => $this->parsePnameTerm(),
            TrigToken::BlankNodeLabel => $this->parseBlankNodeLabel(),
            TrigToken::LSquare => $this->parseBlankNodePropertyList(),
            TrigToken::LParen => $this->parseCollection(),
            TrigToken::String => $this->parseLiteral(),
            TrigToken::Integer, TrigToken::Decimal, TrigToken::Double => $this->parseNumericLiteral(),
            TrigToken::True, TrigToken::False => $this->parseBooleanLiteral(),
            default => $this->neverReachedFallback('object', $type),
        };

        // parseLiteral() advances the reader, everything else does not.
        if ($type !== TrigToken::String) {
            $this->reader->next();
        }

        return $result;
    }

    private function parseIriTerm(): Iri
    {
        $type     = $this->reader->getTokenType();
        $value    = $this->reader->getTokenValue();
        $resolved = $type === TrigToken::IriRef
            ? $this->resolveIriRef($value)
            : $this->expandPname($type, $value);

        return new Iri($resolved);
    }

    private function parsePnameTerm(): Iri|BlankNode
    {
        $type  = $this->reader->getTokenType();
        $value = $this->reader->getTokenValue();
        if ($type === TrigToken::BlankNodeLabel) {
            assert($value !== '', 'BLANK_NODE_LABEL is empty');

            return $this->blankNode($value);
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

        return $this->blankNode($label);
    }

    /**
     * Parse a blank node property list: [ predicateObjectList ].
     */
    private function parseBlankNodePropertyList(bool $mayBeEmpty = true): BlankNode
    {
        $bnode            = $this->blankNode(null);
        $savedSubject     = $this->curSubject;
        $savedPredicate   = $this->curPredicate;
        $this->curSubject = $bnode;

        $this->reader->next();
        $type = $this->reader->getTokenType();
        assert($type !== TrigToken::EndOfInput, 'expected predicateObjectList or ]');

        $this->lastBlankNodePropertyListWasEmpty = $type === TrigToken::RSquare;
        if ($type !== TrigToken::RSquare) {
            $this->parsePredicateObjectList(TrigToken::RSquare);
        }

        $this->curSubject   = $savedSubject;
        $this->curPredicate = $savedPredicate;

        return $bnode;
    }

    private function parseCollection(): Iri|BlankNode
    {
        $this->reader->next();

        $objects = [];
        while (
            ! in_array(
                $this->reader->getTokenType(),
                [TrigToken::RParen, TrigToken::Dot],
                true,
            )
        ) {
            $objects[] = $this->parseObject();
        }

        $rdfNil = new Iri(self::RDF_NAMESPACE . 'nil');
        if ($objects === []) {
            return $rdfNil;
        }

        $rdfFirst = new Iri(self::RDF_NAMESPACE . 'first');
        $rdfRest  = new Iri(self::RDF_NAMESPACE . 'rest');
        $graph    = $this->isTrig ? $this->curGraph : null;

        $headNode    = null;
        $currentNode = null;
        foreach ($objects as $item) {
            $listNode = $this->blankNode(null);
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

        if (! $this->reader->next()) {
            return new Literal($lexical);
        }

        $token = $this->reader->getTokenType();
        if ($token === TrigToken::AtKeyword) {
            $lang = $this->reader->getTokenValue();
            assert($lang !== '', 'LANGTAG is empty');
            $this->reader->next();

            return new Literal($lexical, $lang);
        }

        if ($token === TrigToken::HatHat) {
            $this->reader->next();
            $type = $this->reader->getTokenType();
            assert($type === TrigToken::IriRef || $type === TrigToken::PnameLn || $type === TrigToken::PnameNs, 'expected datatype iri after ^^');

            $datatype = $this->parseIriTerm()->iri;
            $this->reader->next();

            return new Literal($lexical, null, $datatype);
        }

        return new Literal($lexical);
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
}
