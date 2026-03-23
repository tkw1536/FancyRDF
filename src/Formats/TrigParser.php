<?php

declare(strict_types=1);

namespace FancyRDF\Formats;

use FancyRDF\Dataset\Quad;
use FancyRDF\Exceptions\NonCompliantInputError;
use FancyRDF\Formats\TrigReader\TrigReader;
use FancyRDF\Formats\TrigReader\TrigToken;
use FancyRDF\Term\BlankNode;
use FancyRDF\Term\Datatype\LangString;
use FancyRDF\Term\Iri;
use FancyRDF\Term\Literal;
use FancyRDF\Uri\UriReference;
use Override;

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

    /**
     * Constructs a new TrigParser.
     *
     * @param bool $strict
     *   Enable strict mode.
     *   In strict mode, additional checks are performed to validate compliance with the standard, and appropriate NonCompliantInputErrors may be thrown.
     */
    public function __construct(
        public readonly bool $strict,
        private readonly TrigReader $reader,
        public readonly bool $isTrig = false,
        private string $base = '',
    ) {
    }

    /** @throws NonCompliantInputError */
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
                default => $this->parseTriplesOrGraphBlock(),
            };
        }
    }

    /**
     * @param bool $isAtDirective true for @prefix (expect DOT after), false for PREFIX
     *
     * @throws NonCompliantInputError
     */
    private function parsePrefixDirective(bool $isAtDirective): void
    {
        $this->reader->next();
        $type = $this->reader->getTokenType();
        if ($this->strict && $type !== TrigToken::PnameNs) {
            throw new NonCompliantInputError('expected PNAME_NS after \'@prefix\'');
        }

        $name = $this->reader->getTokenValue();

        $this->reader->next();
        $type = $this->reader->getTokenType();
        if ($this->strict && $type !== TrigToken::IriRef) {
            throw new NonCompliantInputError('expected IRIREF after PNAME_NS');
        }

        $iriRef = $this->reader->getTokenValue();

        $iri                     = $this->resolveIriRef($iriRef);
        $this->namespaces[$name] = $iri;

        if (! $isAtDirective) {
            return;
        }

        $next = $this->reader->next();
        if ($this->strict && (! $next || $this->reader->getTokenType() !== TrigToken::Dot)) {
            throw new NonCompliantInputError('expected \'.\' after @prefix');
        }
    }

    /**
     * @param bool $isAtDirective true for @base (expect DOT after), false for BASE
     *
     * @throws NonCompliantInputError
     */
    private function parseBaseDirective(bool $isAtDirective): void
    {
        $this->reader->next();
        $type = $this->reader->getTokenType();
        if ($this->strict && $type !== TrigToken::IriRef) {
            throw new NonCompliantInputError('expected IRIREF after \'@base\'');
        }

        $iriRef     = $this->reader->getTokenValue();
        $this->base = $this->resolveIriRef($iriRef);

        if (! $isAtDirective) {
            return;
        }

        $next = $this->reader->next();
        if ($this->strict && (! $next || $this->reader->getTokenType() !== TrigToken::Dot)) {
            throw new NonCompliantInputError('expected \'.\' after @prefix');
        }
    }

    /** @throws NonCompliantInputError */
    private function parseWrappedGraphDefault(): void
    {
        if ($this->strict && ! $this->isTrig) {
            throw new NonCompliantInputError('UNEXPECTED \'{\' in Turtle mode');
        }

        $this->curGraph = null;
        $this->parseTriplesBlock();
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

    /** @throws NonCompliantInputError */
    private function parseGraphKeywordBlock(): void
    {
        if ($this->strict && ! $this->isTrig) {
            throw new NonCompliantInputError('GRAPH keyword not allowed in Turtle mode');
        }

        $this->reader->next();
        $type = $this->reader->getTokenType();

        $label = $type !== TrigToken::LParen ? $this->parseResource($type) : null;
        if ($this->strict && ($label === null || $type === TrigToken::EndOfInput)) {
            throw new NonCompliantInputError('expected label after GRAPH');
        }

        $this->curGraph = $label;

        $next = $this->reader->next();
        if ($this->strict && (! $next || $this->reader->getTokenType() !== TrigToken::LCurly)) {
            throw new NonCompliantInputError('expected \'{\' after GRAPH');
        }

        $this->parseTriplesBlock();
    }

    /** @throws NonCompliantInputError */
    private function parseTriplesOrGraphBlock(): void
    {
        $type            = $this->reader->getTokenType();
        $maybeGraphBlock = $this->canStartGraphBlock($type);

        $subject          = $this->parseSubject($type);
        $allowSoleSubject = $type === TrigToken::LSquare;

        $this->reader->next();
        $type = $this->reader->getTokenType();

        // We have a graph block: <subject> { ... }
        if ($type === TrigToken::LCurly) {
            if ($this->strict && ! $maybeGraphBlock && ! ($allowSoleSubject && $this->lastBlankNodePropertyListWasEmpty)) {
                throw new NonCompliantInputError('blank node property list not allowed as graph label');
            }

            $this->curGraph = $subject;
            $this->parseTriplesBlock();

            return;
        }

        // we want a list of predicates and objects.
        $this->curSubject = $subject;
        $this->parsePredicateObjectList(null, $allowSoleSubject);
    }

    /** @throws NonCompliantInputError */
    private function parseTriplesBlock(): void
    {
        while (
            $this->reader->getTokenType() !== TrigToken::RCurly &&
            $this->reader->next()
        ) {
            // triples block is closing ....
            $type = $this->reader->getTokenType();
            if ($type === TrigToken::RCurly) {
                break;
            }

            if ($this->strict && $type === TrigToken::Dot) {
                throw new NonCompliantInputError('unexpected .');
            }

            $this->curSubject = $this->parseSubject($type);
            $allowSoleSubject = $type === TrigToken::LSquare;

            $this->reader->next();
            $type = $this->reader->getTokenType();
            if ($this->strict && ! ($type !== TrigToken::RCurly || $allowSoleSubject)) {
                throw new NonCompliantInputError('expected verb or }');
            }

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
            || $type === $endToken;
    }

    /** @throws NonCompliantInputError */
    private function parsePredicateObjectList(TrigToken|null $endToken = null, bool $allowSoleSubject = false): void
    {
        $type = $this->reader->getTokenType();

        $hasPredicate = false;
        $hasNext      = true;
        while ($hasNext) {
            while ($type === TrigToken::Semicolon) {
                $this->reader->next();
                $type = $this->reader->getTokenType();

                if ($this->strict && $type === TrigToken::EndOfInput) {
                    throw new NonCompliantInputError('expected verb or .' . ($endToken !== null ? ' or ' . $endToken->value : ''));
                }
            }

            if ($this->isPredicateObjectListTerminator($type, $endToken)) {
                if ($this->strict && $type === TrigToken::EndOfInput) {
                    throw new NonCompliantInputError('unexpected end of input, expected ' . $endToken?->value);
                }

                if ($this->strict && ! ($hasPredicate || $allowSoleSubject || $type !== TrigToken::Dot)) {
                    throw new NonCompliantInputError('expected verb');
                }

                break;
            }

            $predicate          = $this->parsePredicate();
            $this->curPredicate = $predicate;

            $hasNext = $this->reader->next();
            if ($this->strict && ! $hasNext) {
                throw new NonCompliantInputError('expected object after verb');
            }

            $this->parseObjectList();
            $hasPredicate = true;

            $next = $this->reader->getTokenType();
            // After an object list we either:
            // - see a DOT (end of subject's predicate list),
            // - see the enclosing end token (e.g. '}'),
            // - or continue with another predicate separated by ';'.
            if ($this->isPredicateObjectListTerminator($next, $endToken)) {
                break;
            }

            if ($this->strict && $next !== TrigToken::Semicolon) {
                throw new NonCompliantInputError('expected ;');
            }

            $hasNext = $this->reader->next();
            if ($this->strict && ! $hasNext) {
                throw new NonCompliantInputError('expected verb or object after ;');
            }

            $type = $this->reader->getTokenType();
        }
    }

    /** @throws NonCompliantInputError */
    private function parseObjectList(): void
    {
        while (true) {
            $object = $this->parseObject();

            if ($this->strict && ($this->curSubject === null || $this->curPredicate === null)) {
                throw new NonCompliantInputError('subject and predicate must be set');
            }

            $nextType         = $this->reader->getTokenType();
            $graphForThisItem = $this->isTrig ? $this->curGraph : null;

            // In TriG mode, encountering a fourth term that looks like a graph
            // label in a top-level triple is non-standard. Keep the extension
            // for production (where assertions may be disabled), but signal a
            // failure under assertions so the W3C negative test passes.
            if (
                $this->strict && (
                (
                    $this->isTrig
                    && $this->curGraph === null
                    && $nextType !== TrigToken::Comma
                    && $nextType !== TrigToken::Dot
                    && in_array(
                        $nextType,
                        [TrigToken::IriRef, TrigToken::PnameLn, TrigToken::PnameNs, TrigToken::BlankNodeLabel],
                        true,
                    )
                )
                )
            ) {
                throw new NonCompliantInputError('TriG input must not use N-Quads graph term');
            }

            $this->emit([
                $this->curSubject ?? $this->neverReachedFallback('subject', false),
                $this->curPredicate ?? $this->neverReachedFallback('predicate', false),
                $object,
                $graphForThisItem,
            ]);

            if ($nextType !== TrigToken::Comma) {
                break;
            }

            $next = $this->reader->next();
            if ($this->strict && ! $next) {
                throw new NonCompliantInputError('expected object after ,');
            }
        }
    }

    public const string FALLBACK_IRI = 'invalid://';

    /**
     * Returns a fallback node that should never be reached in valid Trig.
     *
     * @param string $expected
     *   String in error message.
     *
     * @throws NonCompliantInputError
     */
    private function neverReachedFallback(string $expected, bool $advance): Iri
    {
        if ($this->strict) {
            throw new NonCompliantInputError('expected ' . $expected . ', got ' . $this->reader->getTokenType()->value);
        }

        if ($advance) {
            $this->reader->next();
        }

        return new Iri(self::FALLBACK_IRI);
    }

    /** @throws NonCompliantInputError */
    private function parseSubject(TrigToken $type): Iri|BlankNode
    {
        return $this->parseResource($type) ?? $this->neverReachedFallback('subject', false);
    }

    /** @throws NonCompliantInputError */
    private function parsePredicate(): Iri
    {
        $type = $this->reader->getTokenType();

        return match ($type) {
            TrigToken::A => new Iri(self::RDF_NAMESPACE . 'type'),
            TrigToken::IriRef => $this->parseIriRef(),
            TrigToken::PnameLn, TrigToken::PnameNs => $this->parsePName(),
            default => $this->neverReachedFallback('predicate', true),
        };
    }

    /**
     * Parses an iri or blank node
     *
     * @throws NonCompliantInputError
     */
    private function parseResource(TrigToken $type): Iri|BlankNode|null
    {
        return match ($type) {
            TrigToken::IriRef => $this->parseIriRef(),
            TrigToken::PnameLn, TrigToken::PnameNs => $this->parsePName(),
            TrigToken::BlankNodeLabel => $this->parseBlankNodeLabel(),
            TrigToken::LSquare => $this->parseBlankNodePropertyList(),
            TrigToken::LParen => $this->parseCollection(),
            default => null,
        };
    }

    /** @throws NonCompliantInputError */
    private function parseObject(): Iri|BlankNode|Literal
    {
        $type = $this->reader->getTokenType();

        $resource = $this->parseResource($type);
        if ($resource !== null) {
            $this->reader->next();

            return $resource;
        }

        return match ($type) {
            TrigToken::String => $this->parseStringLiteral(),
            TrigToken::Integer, TrigToken::Decimal, TrigToken::Double => $this->parseNumericLiteral(),
            TrigToken::True, TrigToken::False => $this->parseBooleanLiteral(),
            default => $this->neverReachedFallback('object', true),
        };
    }

    /** @throws NonCompliantInputError */
    private function parseIriRef(): Iri
    {
        $value    = $this->reader->getTokenValue();
        $resolved = $this->resolveIriRef($value);

        return new Iri($resolved);
    }

    /** @throws NonCompliantInputError */
    private function parsePName(): Iri
    {
        $value = $this->reader->getTokenValue();

        $colonPos = strpos($value, ':');
        if ($colonPos === false) {
            if ($this->strict) {
                throw new NonCompliantInputError('PNAME must contain :');
            }

            return new Iri(self::FALLBACK_IRI);
        }

        $prefix = substr($value, 0, $colonPos + 1);
        $local  = substr($value, $colonPos + 1);

        $ns = $this->namespaces[$prefix] ?? null;
        if ($ns === null) {
            if ($this->strict) {
                throw new NonCompliantInputError('undefined prefix: ' . $prefix);
            }

            return new Iri(self::FALLBACK_IRI);
        }

        $full = $ns . $local;

        if ($this->base !== '') {
            $full = UriReference::resolveRelative($this->base, $full);
        }

        if ($full === '') {
            if ($this->strict) {
                throw new NonCompliantInputError('expanded IRI is empty');
            }

            return new Iri(self::FALLBACK_IRI);
        }

        return new Iri($full);
    }

    /**
     * @return non-empty-string
     *
     * @throws NonCompliantInputError
     */
    private function resolveIriRef(string $decodedIri): string
    {
        if ($this->strict && preg_match('/[\x00-\x20<>"{}|^`\\\\]/u', $decodedIri) === 1) {
            throw new NonCompliantInputError('IRIREF contains disallowed character');
        }

        $iri = $this->base !== '' ? UriReference::resolveRelative($this->base, $decodedIri) : $decodedIri;
        if ($iri === '') {
            if ($this->strict) {
                throw new NonCompliantInputError('resolved IRI is empty');
            }

            return self::FALLBACK_IRI;
        }

        return $iri;
    }

    /** @throws NonCompliantInputError */
    private function parseBlankNodeLabel(): BlankNode
    {
        $label = $this->reader->getTokenValue();
        if ($label === '') {
            if ($this->strict) {
                throw new NonCompliantInputError('BLANK_NODE_LABEL is empty');
            }

            return $this->blankNode(null);
        }

        return $this->blankNode($label);
    }

    /**
     * Parse a blank node property list: [ predicateObjectList ].
     *
     * @throws NonCompliantInputError
     */
    private function parseBlankNodePropertyList(bool $mayBeEmpty = true): BlankNode
    {
        $bnode            = $this->blankNode(null);
        $savedSubject     = $this->curSubject;
        $savedPredicate   = $this->curPredicate;
        $this->curSubject = $bnode;

        $this->reader->next();
        $type = $this->reader->getTokenType();
        if ($this->strict && $type === TrigToken::EndOfInput) {
            throw new NonCompliantInputError('expected predicateObjectList or ]');
        }

        $this->lastBlankNodePropertyListWasEmpty = $type === TrigToken::RSquare;
        if ($type !== TrigToken::RSquare) {
            $this->parsePredicateObjectList(TrigToken::RSquare);
        }

        $this->curSubject   = $savedSubject;
        $this->curPredicate = $savedPredicate;

        return $bnode;
    }

    /** @throws NonCompliantInputError */
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

    /** @throws NonCompliantInputError */
    private function parseStringLiteral(): Literal
    {
        $lexical  = $this->reader->getTokenValue();
        $lang     = null;
        $datatype = null;

        if (! $this->reader->next()) {
            return Literal::XSDString($lexical);
        }

        $token = $this->reader->getTokenType();
        if ($token === TrigToken::AtKeyword) {
            $lang = $this->reader->getTokenValue();
            if ($lang === '') {
                if ($this->strict) {
                    throw new NonCompliantInputError('LANGTAG is empty');
                }

                $lang = null;
            }

            $this->reader->next();

            return Literal::langOrXSDString($lexical, $lang);
        }

        if ($token === TrigToken::HatHat) {
            $this->reader->next();
            $type     = $this->reader->getTokenType();
            $datatype = match ($type) {
                TrigToken::IriRef => $this->parseIriRef()->iri,
                default => $this->parsePName()->iri,
            };
            $this->reader->next();

            if ($datatype === LangString::IRI) {
                if ($this->strict) {
                    throw new NonCompliantInputError('LangString literals cannot be created with a datatype IRI');
                }

                return Literal::XSDString($lexical);
            }

            return Literal::typed($lexical, $datatype);
        }

        return Literal::XSDString($lexical);
    }

    /** @throws NonCompliantInputError */
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

        $this->reader->next();

        return Literal::typed($value, $datatype);
    }

    /** @throws NonCompliantInputError */
    private function parseBooleanLiteral(): Literal
    {
        $value = $this->reader->getTokenValue();
        $this->reader->next();

        return Literal::typed($value, self::XSD_NAMESPACE . 'boolean');
    }
}
