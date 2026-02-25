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
use function hexdec;
use function is_string;
use function mb_chr;
use function preg_match;
use function str_ends_with;
use function str_starts_with;
use function strlen;
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

            match ($type) {
                TrigToken::AtPrefix, TrigToken::Prefix => $this->parsePrefixDirective($type),
                TrigToken::AtBase, TrigToken::Base => $this->parseBaseDirective($type),
                TrigToken::LCurly => $this->parseWrappedGraphDefault(),
                TrigToken::Graph => $this->parseGraphKeywordBlock(),
                default => $this->parseTriplesOrGraphBlock($type),
            };
        }
    }

    private function parsePrefixDirective(TrigToken $directiveType): void
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

        if ($directiveType !== TrigToken::AtPrefix) {
            return;
        }

        $this->reader->next();
        assert($this->reader->getTokenType() === TrigToken::Dot, 'expected \'.\' after @prefix');
    }

    private function parseBaseDirective(TrigToken $directiveType): void
    {
        $this->reader->next();
        $type = $this->reader->getTokenType();
        assert($type === TrigToken::IriRef, 'expected IRIREF after \'@base\'');

        $iriRef     = $this->reader->getTokenValue();
        $this->base = $this->resolveIriRef($iriRef);

        if ($directiveType !== TrigToken::AtBase) {
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
        $type = $this->reader->getTokenType();
        switch ($type) {
            case TrigToken::IriRef:
                $label = $this->parseIriRefTerm();
                break;
            case TrigToken::PnameLn:
            case TrigToken::PnameNs:
                $label = $this->parsePnameTerm();
                break;
            case TrigToken::BlankNodeLabel:
                $label = $this->parseBlankNodeLabel();
                break;
            case TrigToken::LSquare:
                $label = $this->makeBlankNode(null);
                $this->reader->next();
                assert($this->reader->getTokenType() === TrigToken::RSquare, 'expected ] after [');
                break;
            default:
                $label = null;
                assert($type !== TrigToken::EndOfInput, 'expected label after GRAPH');
                break;
        }

        $this->curGraph = $label;

        $this->reader->next();
        assert($this->reader->getTokenType() === TrigToken::LCurly, 'expected {');

        $this->parseTriplesBlock();
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

        $mayBeGraphBlock = (
            $this->isTrig &&
            (
                $type === TrigToken::IriRef ||
                $type === TrigToken::PnameLn ||
                $type === TrigToken::PnameNs ||
                $type === TrigToken::BlankNodeLabel
            )
        );
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

    private function parsePredicateObjectList(TrigToken $verbType, TrigToken|null $endToken = null, bool $allowSoleSubject = false): void
    {
        $hasPredicate = false;
        while (true) {
            while ($verbType === TrigToken::Semicolon) {
                $hasNext = $this->reader->next();
                assert($hasNext, 'expected verb or .' . ($endToken !== null ? ' or ' . $endToken->value : ''));
                $verbType = $this->reader->getTokenType();
            }

            if ($verbType === TrigToken::Dot || ($endToken !== null && $verbType === $endToken) || $verbType === TrigToken::LCurly || $verbType === TrigToken::RSquare || $verbType === TrigToken::EndOfInput) {
                assert($hasPredicate || $allowSoleSubject || $verbType !== TrigToken::Dot, 'expected verb');
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

            // assert(, 'expected ; or .' . ($endToken !== null ? ' or ' . $endToken->value : ''));
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

    private function parseIriRefTerm(): Iri
    {
        $value    = $this->reader->getTokenValue();
        $resolved = $this->resolveIriRef($value);

        return new Iri($resolved);
    }

    private function parseIriTerm(TrigToken $type): Iri
    {
        $value    = $this->reader->getTokenValue();
        $resolved = $this->expandPnameOrIriRef($type, $value);

        return new Iri($resolved);
    }

    private function parsePnameTerm(): Iri|BlankNode
    {
        $type  = $this->reader->getTokenType();
        $value = $this->reader->getTokenValue();
        if ($type === TrigToken::BlankNodeLabel) {
            assert($value !== '', 'BLANK_NODE_LABEL is empty');

            return $this->makeBlankNode($value);
        }

        $resolved = $this->expandPnameOrIriRef($type, $value);

        return new Iri($resolved);
    }

    /** @return non-empty-string */
    private function resolveIriRef(string $tokenValue): string
    {
        assert(strlen($tokenValue) >= 2 && $tokenValue[0] === '<' && $tokenValue[strlen($tokenValue) - 1] === '>', 'IRIREF must be <...>');
        $inner     = substr($tokenValue, 1, -1);
        $unescaped = $this->unescapeIri($inner);
        assert(preg_match('/[\x00-\x20<>"{}|^`\\\\]/u', $unescaped) !== 1, 'IRIREF contains disallowed character');

        $iri = $this->base !== '' ? UriReference::resolveRelative($this->base, $unescaped) : $unescaped;
        assert($iri !== '', 'resolved IRI is empty');

        return $iri;
    }

    /** @return non-empty-string */
    private function expandPnameOrIriRef(TrigToken $type, string $tokenValue): string
    {
        if ($type === TrigToken::IriRef) {
            return $this->resolveIriRef($tokenValue);
        }

        assert($type === TrigToken::PnameLn || $type === TrigToken::PnameNs, 'expected PNAME');
        $colonPos = strpos($tokenValue, ':');
        assert($colonPos !== false, 'PNAME must contain :');
        $prefix = substr($tokenValue, 0, $colonPos + 1);
        $local  = substr($tokenValue, $colonPos + 1);
        $local  = $this->unescapePnameLocal($local);

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
        $value = $this->reader->getTokenValue();
        assert(strlen($value) >= 2 && substr($value, 0, 2) === '_:', 'BLANK_NODE_LABEL must start with _:');
        $label = substr($value, 2);
        if (str_ends_with($label, '.')) {
            $label = substr($label, 0, -1);
        }

        assert($label !== '', 'BLANK_NODE_LABEL is empty');

        return $this->makeBlankNode($label);
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

    /**
     * Parse a blank node used as subject: [ predicateObjectList ].
     * Leaves the reader on the closing ] so that the caller (e.g. parseTriples) can
     * call next() to advance to the verb.
     */
    private function parseBlankNodePropertyListSubject(): BlankNode
    {
        $savedSubject   = $this->curSubject;
        $savedPredicate = $this->curPredicate;

        $bnode            = $this->makeBlankNode(null);
        $this->curSubject = $bnode;

        $this->reader->next();
        $type = $this->reader->getTokenType();

        $this->lastBlankNodePropertyListWasEmpty = $type === TrigToken::RSquare;
        if ($type !== TrigToken::RSquare) {
            $this->parsePredicateObjectList($type, TrigToken::RSquare);
        }

        $this->curSubject   = $savedSubject;
        $this->curPredicate = $savedPredicate;

        return $bnode;
    }

    /**
     * Parse a blank node used as object: [ predicateObjectList ].
     * Consumes the closing ] so that callers are positioned on the next token.
     */
    private function parseBlankNodePropertyListObject(): BlankNode
    {
        $bnode            = $this->makeBlankNode(null);
        $savedSubject     = $this->curSubject;
        $savedPredicate   = $this->curPredicate;
        $this->curSubject = $bnode;
        $hasNext          = $this->reader->next();
        assert($hasNext, 'expected predicateObjectList or ]');
        $type = $this->reader->getTokenType();
        if ($type !== TrigToken::RSquare) {
            $this->parsePredicateObjectList($type, TrigToken::RSquare);
        }

        assert($this->reader->getTokenType() === TrigToken::RSquare, 'expected ] after blank node property list');
        $this->reader->next();

        $this->curSubject   = $savedSubject;
        $this->curPredicate = $savedPredicate;

        return $bnode;
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
        $value    = $this->reader->getTokenValue();
        $lexical  = $this->unescapeString($value);
        $lang     = null;
        $datatype = null;
        if ($this->reader->next()) {
            if ($this->reader->getTokenType() === TrigToken::LangTag) {
                $lang = $this->reader->getTokenValue();
                assert(str_starts_with($lang, '@'), 'LANGTAG must start with @');
                $lang = substr($lang, 1);
                assert($lang !== '', 'LANGTAG is empty');
                $this->reader->next();
            } elseif ($this->reader->getTokenType() === TrigToken::HatHat) {
                $hasNext = $this->reader->next();
                assert($hasNext, 'expected iri after ^^');
                $type = $this->reader->getTokenType();
                assert($type === TrigToken::IriRef || $type === TrigToken::PnameLn || $type === TrigToken::PnameNs, 'expected datatype iri');
                $datatype = $this->expandPnameOrIriRef($type, $this->reader->getTokenValue());
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

    private function unescapeIri(string $s): string
    {
        $result = '';
        $i      = 0;
        $len    = strlen($s);
        while ($i < $len) {
            if ($s[$i] === '\\') {
                assert($i + 1 < $len && ($s[$i + 1] === 'u' || $s[$i + 1] === 'U'), 'only \\u and \\U allowed in IRIREF');
                $result .= $this->decodeUcharFromString($s, $i);
                $hexLen  = $s[$i + 1] === 'u' ? 4 : 8;
                $i      += 2 + $hexLen;
                continue;
            }

            $result .= $s[$i];
            $i++;
        }

        return $result;
    }

    private function unescapeString(string $tokenValue): string
    {
        $len = strlen($tokenValue);
        if ($len < 2) {
            return $tokenValue;
        }

        $delim    = $tokenValue[0];
        $isLong   = false;
        $start    = 1;
        $innerLen = $len - 2;
        if (($delim === '"' || $delim === "'") && $len >= 6 && substr($tokenValue, 0, 3) === $delim . $delim . $delim) {
            $isLong   = true;
            $start    = 3;
            $innerLen = $len - 6;
        }

        $inner = substr($tokenValue, $start, $innerLen);

        return $this->unescapeStringInner($inner);
    }

    private function unescapeStringInner(string $s): string
    {
        $result = '';
        $i      = 0;
        $len    = strlen($s);
        while ($i < $len) {
            if ($s[$i] === '\\' && $i + 1 < $len) {
                $next = $s[$i + 1];
                if ($next === 'u' || $next === 'U') {
                    $result .= $this->decodeUcharFromString($s, $i);
                    $hexLen  = $next === 'u' ? 4 : 8;
                    $i      += 2 + $hexLen;
                    continue;
                }

                $result .= $this->decodeEchar($next);
                $i      += 2;
                continue;
            }

            $result .= $s[$i];
            $i++;
        }

        return $result;
    }

    private function decodeUcharFromString(string $s, int $pos): string
    {
        assert($s[$pos] === '\\' && $pos + 2 <= strlen($s));
        $u      = $s[$pos + 1] === 'u';
        $hexLen = $u ? 4 : 8;
        assert($pos + 2 + $hexLen <= strlen($s), 'incomplete \\u or \\U escape');
        $hex = substr($s, $pos + 2, $hexLen);
        assert(preg_match('/^[0-9A-Fa-f]+$/', $hex) === 1, 'invalid hex in escape');
        $ord = (int) @hexdec($hex);
        assert($ord <= 0x10FFFF, 'code point out of range');
        assert($ord < 0xD800 || $ord > 0xDFFF, 'surrogate code point in escape');
        $res = mb_chr($ord, 'UTF-8');

        /** @phpstan-ignore function.alreadyNarrowedType (in production mode the assertion above may fail) */
        return is_string($res) ? $res : '';
    }

    private function decodeEchar(string $char): string
    {
        $result = match ($char) {
            't' => "\t",
            'b' => "\x08",
            'n' => "\n",
            'r' => "\r",
            'f' => "\f",
            '"' => '"',
            "'" => "'",
            '\\' => '\\',
            default => '',
        };

        assert($result !== '', 'invalid string escape \\' . $char);

        return $result;
    }

    private function unescapePnameLocal(string $local): string
    {
        $result = '';
        $i      = 0;
        $len    = strlen($local);
        while ($i < $len) {
            if ($local[$i] === '\\' && $i + 1 < $len) {
                $result .= $local[$i + 1];
                $i      += 2;
                continue;
            }

            $result .= $local[$i];
            $i++;
        }

        return $result;
    }
}
