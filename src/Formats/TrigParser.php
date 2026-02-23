<?php

declare(strict_types=1);

namespace FancyRDF\Formats;

use Exception;
use FancyRDF\Dataset\Quad;
use FancyRDF\Formats\TrigReader\TrigReader;
use FancyRDF\Formats\TrigReader\TrigTokenType;
use FancyRDF\Term\BlankNode;
use FancyRDF\Term\Iri;
use FancyRDF\Term\Literal;
use FancyRDF\Uri\UriReference;
use Override;

use function assert;
use function hexdec;
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

    private string $base = '';

    /** @var array<string, string> */
    private array $namespaces = [];

    /** @var array<string, BlankNode> */
    private array $bnodeLabels = [];

    private int $anonCounter = 0;

    private Iri|BlankNode|null $curSubject = null;

    private Iri|null $curPredicate = null;

    /** @var Iri|BlankNode|null (TriG only) */
    private Iri|BlankNode|null $curGraph = null;

    public function __construct(
        private readonly TrigReader $reader,
        private readonly bool $isTrig = false,
    ) {
    }

    #[Override]
    protected function doIterate(): void
    {
        while ($this->reader->next()) {
            $type = $this->reader->getTokenType();
            if ($type === TrigTokenType::EndOfInput) {
                break;
            }

            $this->parseStatement($type);
        }
    }

    private function parseStatement(TrigTokenType $type): void
    {
        switch ($type) {
            case TrigTokenType::AtPrefix:
            case TrigTokenType::Prefix:
                $this->parsePrefixDirective($type);

                return;

            case TrigTokenType::AtBase:
            case TrigTokenType::Base:
                $this->parseBaseDirective($type);

                return;

            case TrigTokenType::LCurly:
                if ($this->isTrig) {
                    $this->parseWrappedGraphDefault();

                    return;
                }

                break;
            case TrigTokenType::Graph:
                if (! $this->isTrig) {
                    $this->parseGraphKeywordBlock();
                }

                assert(! $this->isTrig, 'GRAPH keyword not allowed in Turtle mode');

                return;

            default:
                break;
        }

        $this->curGraph = $this->isTrig ? null : null;
        $this->parseTriplesOrGraphBlock($type);
    }

    private function parsePrefixDirective(TrigTokenType $directiveType): void
    {
        $hasNext = $this->reader->next();
        assert($hasNext, 'expected PNAME_NS after prefix directive');
        $type = $this->reader->getTokenType();
        assert($type === TrigTokenType::PnameNs, 'expected PNAME_NS');
        $pnameNs = $this->reader->getTokenValue();

        $hasNext = $this->reader->next();
        assert($hasNext, 'expected IRIREF after PNAME_NS');
        $type = $this->reader->getTokenType();
        assert($type === TrigTokenType::IriRef, 'expected IRIREF');
        $iriRef = $this->reader->getTokenValue();
        $iri    = $this->resolveIriRef($iriRef);

        $this->namespaces[$pnameNs] = $iri;

        if ($directiveType !== TrigTokenType::AtPrefix) {
            return;
        }

        $hasNext = $this->reader->next();
        assert($hasNext, 'expected DOT after @prefix');
        assert($this->reader->getTokenType() === TrigTokenType::Dot, 'expected DOT');
    }

    private function parseBaseDirective(TrigTokenType $directiveType): void
    {
        $hasNext = $this->reader->next();
        assert($hasNext, 'expected IRIREF after base directive');
        $type = $this->reader->getTokenType();
        assert($type === TrigTokenType::IriRef, 'expected IRIREF');
        $iriRef     = $this->reader->getTokenValue();
        $this->base = $this->resolveIriRef($iriRef);

        if ($directiveType !== TrigTokenType::AtBase) {
            return;
        }

        $hasNext = $this->reader->next();
        assert($hasNext, 'expected DOT after @base');
        assert($this->reader->getTokenType() === TrigTokenType::Dot, 'expected DOT');
    }

    private function parseWrappedGraphDefault(): void
    {
        $this->curGraph = null;
        $this->parseTriplesBlock();
        assert($this->reader->getTokenType() === TrigTokenType::RCurly, 'expected }');
    }

    private function parseGraphKeywordBlock(): void
    {
        $hasNext = $this->reader->next();
        assert($hasNext, 'expected label after GRAPH');
        $label = $this->parseLabelOrSubject();
        assert($label !== null, 'expected graph label');
        $this->curGraph = $label;

        $hasNext = $this->reader->next();
        assert($hasNext, 'expected LCurly');
        assert($this->reader->getTokenType() === TrigTokenType::LCurly, 'expected {');
        $this->parseTriplesBlock();
        assert($this->reader->getTokenType() === TrigTokenType::RCurly, 'expected }');
    }

    private function parseTriplesOrGraphBlock(TrigTokenType $firstType): void
    {
        if ($this->isTrig && ($firstType === TrigTokenType::IriRef || $firstType === TrigTokenType::PnameLn || $firstType === TrigTokenType::PnameNs || $firstType === TrigTokenType::BlankNodeLabel)) {
            $label = $this->parseLabelOrSubjectFromCurrent();
            if ($label !== null) {
                // Skip to LCurly (graph block) or verb (subject of triple)
                $nextType = TrigTokenType::EndOfInput;
                while ($this->reader->next()) {
                    $nextType = $this->reader->getTokenType();
                    if ($nextType === TrigTokenType::LCurly) {
                        break;
                    }

                    if ($nextType === TrigTokenType::IriRef || $nextType === TrigTokenType::PnameLn || $nextType === TrigTokenType::PnameNs || $nextType === TrigTokenType::A) {
                        break;
                    }
                }

                if ($nextType === TrigTokenType::LCurly) {
                    $this->curGraph = $label;
                    $this->parseTriplesBlock();
                    assert($this->reader->getTokenType() === TrigTokenType::RCurly, 'expected }');

                    return;
                }

                $this->curGraph   = null;
                $this->curSubject = $label;
                $this->parsePredicateObjectList($nextType);

                return;
            }
        }

        $this->curGraph   = null;
        $subject          = $this->parseSubject($firstType);
        $this->curSubject = $subject;
        $hasNext          = $this->reader->next();
        assert($hasNext, 'expected verb');
        $verbType = $this->reader->getTokenType();
        $this->parsePredicateObjectList($verbType);
    }

    private function parseTriplesBlock(): void
    {
        if (! $this->reader->next()) {
            return;
        }

        $type = $this->reader->getTokenType();
        if ($type === TrigTokenType::RCurly) {
            return;
        }

        $this->parseTriples($type, TrigTokenType::RCurly);
        if ($this->reader->getTokenType() === TrigTokenType::RCurly || $this->reader->getTokenType() === TrigTokenType::LCurly) {
            return;
        }

        while ($this->reader->next()) {
            $type = $this->reader->getTokenType();
            if ($type === TrigTokenType::RCurly) {
                break;
            }

            if ($type === TrigTokenType::LCurly) {
                return;
            }

            if ($type === TrigTokenType::Dot) {
                if (! $this->reader->next()) {
                    break;
                }

                $type = $this->reader->getTokenType();
                if ($type === TrigTokenType::RCurly) {
                    break;
                }

                if ($type === TrigTokenType::LCurly) {
                    return;
                }
            }

            $this->parseTriples($type, TrigTokenType::RCurly);
            if ($this->reader->getTokenType() === TrigTokenType::RCurly || $this->reader->getTokenType() === TrigTokenType::LCurly) {
                return;
            }
        }
    }

    private function parseTriples(TrigTokenType $subjectType, TrigTokenType|null $endToken = null): void
    {
        $this->curSubject = $this->parseSubject($subjectType);
        $hasNext          = $this->reader->next();
        assert($hasNext, 'expected verb');
        $verbType = $this->reader->getTokenType();
        if ($endToken !== null && $verbType === $endToken) {
            return;
        }

        $this->parsePredicateObjectList($verbType, $endToken);
    }

    private function parsePredicateObjectList(TrigTokenType $verbType, TrigTokenType|null $endToken = null): void
    {
        while (true) {
            while ($verbType === TrigTokenType::Semicolon) {
                $hasNext = $this->reader->next();
                assert($hasNext, 'expected verb or .' . ($endToken !== null ? ' or ' . $endToken->value : ''));
                $verbType = $this->reader->getTokenType();
            }

            if ($verbType === TrigTokenType::Dot || ($endToken !== null && $verbType === $endToken) || $verbType === TrigTokenType::LCurly || $verbType === TrigTokenType::RSquare) {
                break;
            }

            $predicate          = $this->parseVerb($verbType);
            $this->curPredicate = $predicate;
            $hasNext            = $this->reader->next();
            assert($hasNext, 'expected object after verb');
            $this->parseObjectList($endToken);

            $next = $this->reader->getTokenType();
            if ($next === TrigTokenType::Dot) {
                break;
            }

            if ($endToken !== null && $next === $endToken) {
                break;
            }

            assert($next === TrigTokenType::Semicolon, 'expected ; or .' . ($endToken !== null ? ' or ' . $endToken->value : ''));
            $verbType = TrigTokenType::Semicolon;
            $hasNext  = $this->reader->next();
            assert($hasNext, 'expected verb or object after ;');
            $verbType = $this->reader->getTokenType();
        }
    }

    private function parseObjectList(TrigTokenType|null $endToken = null): void
    {
        $this->parseObject();
        $this->consumeIfObjectToken($endToken);
        while ($this->reader->getTokenType() === TrigTokenType::Comma) {
            $hasNext = $this->reader->next();
            assert($hasNext, 'expected object after ,');
            $this->parseObject();
            $this->consumeIfObjectToken($endToken);
        }
    }

    private function consumeIfObjectToken(TrigTokenType|null $endToken): void
    {
        $type = $this->reader->getTokenType();
        if ($type === TrigTokenType::Comma || $type === TrigTokenType::Dot || $type === TrigTokenType::Semicolon) {
            return;
        }

        if ($endToken !== null && $type === $endToken) {
            return;
        }

        $this->reader->next();
    }

    private function parseSubject(TrigTokenType $type): Iri|BlankNode
    {
        return match ($type) {
            TrigTokenType::IriRef => $this->parseIriRefTerm(),
            TrigTokenType::PnameLn, TrigTokenType::PnameNs => $this->parsePnameTerm(),
            TrigTokenType::BlankNodeLabel => $this->parseBlankNodeLabel(),
            TrigTokenType::LSquare => $this->parseBlankNodePropertyListSubject(),
            TrigTokenType::LParen => $this->parseCollectionSubject(),
            default => throw new Exception('expected subject, got ' . $type->value),
        };
    }

    /** @return Iri|BlankNode|null null only when we haven't consumed a label (Turtle mode) */
    private function parseLabelOrSubject(): Iri|BlankNode|null
    {
        $type = $this->reader->getTokenType();

        return $this->parseLabelOrSubjectFromType($type);
    }

    private function parseLabelOrSubjectFromCurrent(): Iri|BlankNode|null
    {
        return $this->parseLabelOrSubjectFromType($this->reader->getTokenType());
    }

    private function parseLabelOrSubjectFromType(TrigTokenType $type): Iri|BlankNode|null
    {
        return match ($type) {
            TrigTokenType::IriRef => $this->parseIriRefTerm(),
            TrigTokenType::PnameLn, TrigTokenType::PnameNs => $this->parsePnameTerm(),
            TrigTokenType::BlankNodeLabel => $this->parseBlankNodeLabel(),
            default => null,
        };
    }

    private function parseVerb(TrigTokenType $type): Iri
    {
        if ($type === TrigTokenType::A) {
            return new Iri(self::RDF_NAMESPACE . 'type');
        }

        assert($type === TrigTokenType::IriRef || $type === TrigTokenType::PnameLn || $type === TrigTokenType::PnameNs, 'expected predicate (iri or a)');

        return $this->parseIriTerm($type);
    }

    private function parseObject(): void
    {
        $type   = $this->reader->getTokenType();
        $object = match ($type) {
            TrigTokenType::IriRef => $this->parseIriRefTerm(),
            TrigTokenType::PnameLn, TrigTokenType::PnameNs => $this->parsePnameTerm(),
            TrigTokenType::BlankNodeLabel => $this->parseBlankNodeLabel(),
            TrigTokenType::LSquare => $this->parseBlankNodePropertyListObject(),
            TrigTokenType::LParen => $this->parseCollectionObject(),
            TrigTokenType::String => $this->parseLiteral(),
            TrigTokenType::Integer, TrigTokenType::Decimal, TrigTokenType::Double => $this->parseNumericLiteral(),
            TrigTokenType::True, TrigTokenType::False => $this->parseBooleanLiteral(),
            default => throw new Exception('expected object, got ' . $type->value),
        };
        assert($this->curSubject !== null && $this->curPredicate !== null, 'subject and predicate must be set');
        $graph = $this->isTrig ? $this->curGraph : null;
        $this->emit([$this->curSubject, $this->curPredicate, $object, $graph]);
    }

    private function parseIriRefTerm(): Iri
    {
        $value    = $this->reader->getTokenValue();
        $resolved = $this->resolveIriRef($value);

        return new Iri($resolved);
    }

    private function parseIriTerm(TrigTokenType $type): Iri
    {
        $value    = $this->reader->getTokenValue();
        $resolved = $this->expandPnameOrIriRef($type, $value);

        return new Iri($resolved);
    }

    private function parsePnameTerm(): Iri|BlankNode
    {
        $type  = $this->reader->getTokenType();
        $value = $this->reader->getTokenValue();
        if ($type === TrigTokenType::BlankNodeLabel) {
            assert($value !== '', 'BLANK_NODE_LABEL is empty');

            return $this->getOrCreateBlankNode($value);
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

        $iri = $this->base !== '' ? UriReference::resolveRelative($this->base, $unescaped) : $unescaped;
        assert($iri !== '', 'resolved IRI is empty');

        return $iri;
    }

    /** @return non-empty-string */
    private function expandPnameOrIriRef(TrigTokenType $type, string $tokenValue): string
    {
        if ($type === TrigTokenType::IriRef) {
            return $this->resolveIriRef($tokenValue);
        }

        assert($type === TrigTokenType::PnameLn || $type === TrigTokenType::PnameNs, 'expected PNAME');
        $colonPos = strpos($tokenValue, ':');
        assert($colonPos !== false, 'PNAME must contain :');
        $prefix = substr($tokenValue, 0, $colonPos + 1);
        assert(isset($this->namespaces[$prefix]), 'undefined prefix: ' . $prefix);
        $local = substr($tokenValue, $colonPos + 1);
        $local = $this->unescapePnameLocal($local);
        $ns    = $this->namespaces[$prefix];
        $full  = $ns . $local;
        if ($this->base !== '' && (strpos($ns, ':') === false || UriReference::parse($ns)->isRelativeReference())) {
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

        return $this->getOrCreateBlankNode($label);
    }

    private function parseAnon(): BlankNode
    {
        return new BlankNode('_anon_' . (++$this->anonCounter));
    }

    /** @param non-empty-string $label */
    private function getOrCreateBlankNode(string $label): BlankNode
    {
        if (! isset($this->bnodeLabels[$label])) {
            $this->bnodeLabels[$label] = new BlankNode($label);
        }

        return $this->bnodeLabels[$label];
    }

    private function parseBlankNodePropertyListSubject(): BlankNode
    {
        $bnode            = $this->parseAnon();
        $savedSubject     = $this->curSubject;
        $savedPredicate   = $this->curPredicate;
        $this->curSubject = $bnode;
        $hasNext          = $this->reader->next();
        assert($hasNext, 'expected predicateObjectList or ]');
        $type = $this->reader->getTokenType();
        if ($type !== TrigTokenType::RSquare) {
            $this->parsePredicateObjectList($type, TrigTokenType::RSquare);
        }

        $this->curSubject   = $savedSubject;
        $this->curPredicate = $savedPredicate;

        return $bnode;
    }

    private function parseBlankNodePropertyListObject(): BlankNode
    {
        $bnode            = $this->parseAnon();
        $savedSubject     = $this->curSubject;
        $savedPredicate   = $this->curPredicate;
        $this->curSubject = $bnode;
        $hasNext          = $this->reader->next();
        assert($hasNext, 'expected predicateObjectList or ]');
        $type = $this->reader->getTokenType();
        if ($type !== TrigTokenType::RSquare) {
            $this->parsePredicateObjectList($type, TrigTokenType::RSquare);
        }

        $this->curSubject   = $savedSubject;
        $this->curPredicate = $savedPredicate;

        return $bnode;
    }

    private function parseCollectionSubject(): Iri|BlankNode
    {
        return $this->parseCollection(true);
    }

    private function parseCollectionObject(): Iri|BlankNode
    {
        return $this->parseCollection(false);
    }

    private function parseCollection(bool $isSubject): Iri|BlankNode
    {
        if (! $this->reader->next()) {
            return new Iri(self::RDF_NAMESPACE . 'nil');
        }

        if ($this->reader->getTokenType() === TrigTokenType::RParen) {
            return new Iri(self::RDF_NAMESPACE . 'nil');
        }

        $objects = [];
        $type    = $this->reader->getTokenType();
        while (true) {
            $obj = match ($type) {
                TrigTokenType::IriRef => $this->parseIriRefTerm(),
                TrigTokenType::PnameLn, TrigTokenType::PnameNs => $this->parsePnameTerm(),
                TrigTokenType::BlankNodeLabel => $this->parseBlankNodeLabel(),
                TrigTokenType::LSquare => $this->parseBlankNodePropertyListObject(),
                TrigTokenType::LParen => $this->parseCollectionObject(),
                TrigTokenType::String => $this->parseLiteral(),
                TrigTokenType::Integer, TrigTokenType::Decimal, TrigTokenType::Double => $this->parseNumericLiteral(),
                TrigTokenType::True, TrigTokenType::False => $this->parseBooleanLiteral(),
                default => throw new Exception('expected object in collection'),
            };
            $objects[] = $obj;
            if (! $this->reader->next()) {
                break;
            }

            $type = $this->reader->getTokenType();
            if ($type === TrigTokenType::RParen) {
                break;
            }
        }

        $rdfFirst    = new Iri(self::RDF_NAMESPACE . 'first');
        $rdfRest     = new Iri(self::RDF_NAMESPACE . 'rest');
        $rdfNil      = new Iri(self::RDF_NAMESPACE . 'nil');
        $graph       = $this->isTrig ? $this->curGraph : null;
        $currentList = null;
        foreach ($objects as $index => $item) {
            $listNode = $this->parseAnon();
            if ($currentList !== null) {
                $this->emit([$currentList, $rdfRest, $listNode, $graph]);
            }

            $this->emit([$listNode, $rdfFirst, $item, $graph]);
            $currentList = $listNode;
        }

        $this->emit([$currentList, $rdfRest, $rdfNil, $graph]);

        return $currentList;
    }

    private function parseLiteral(): Literal
    {
        $value    = $this->reader->getTokenValue();
        $lexical  = $this->unescapeString($value);
        $lang     = null;
        $datatype = null;
        if ($this->reader->next()) {
            if ($this->reader->getTokenType() === TrigTokenType::LangTag) {
                $lang = $this->reader->getTokenValue();
                assert(str_starts_with($lang, '@'), 'LANGTAG must start with @');
                $lang = substr($lang, 1);
                assert($lang !== '', 'LANGTAG is empty');
            } elseif ($this->reader->getTokenType() === TrigTokenType::HatHat) {
                $hasNext = $this->reader->next();
                assert($hasNext, 'expected iri after ^^');
                $type = $this->reader->getTokenType();
                assert($type === TrigTokenType::IriRef || $type === TrigTokenType::PnameLn || $type === TrigTokenType::PnameNs, 'expected datatype iri');
                $datatype = $this->expandPnameOrIriRef($type, $this->reader->getTokenValue());
            }
        }

        return new Literal($lexical, $lang, $datatype);
    }

    private function parseNumericLiteral(): Literal
    {
        $type     = $this->reader->getTokenType();
        $value    = $this->reader->getTokenValue();
        $datatype = match ($type) {
            TrigTokenType::Integer => self::XSD_NAMESPACE . 'integer',
            TrigTokenType::Decimal => self::XSD_NAMESPACE . 'decimal',
            TrigTokenType::Double => self::XSD_NAMESPACE . 'double',
            default => self::XSD_NAMESPACE . 'decimal',
        };

        return new Literal($value, null, $datatype);
    }

    private function parseBooleanLiteral(): Literal
    {
        $type  = $this->reader->getTokenType();
        $value = $this->reader->getTokenValue();

        return new Literal($value, null, self::XSD_NAMESPACE . 'boolean');
    }

    private function unescapeIri(string $s): string
    {
        return $this->unescapeUcharOnly($s);
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

    private function unescapeUcharOnly(string $s): string
    {
        $result = '';
        $i      = 0;
        $len    = strlen($s);
        while ($i < $len) {
            if ($s[$i] === '\\' && $i + 1 < $len && ($s[$i + 1] === 'u' || $s[$i + 1] === 'U')) {
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

    private function decodeUcharFromString(string $s, int $pos): string
    {
        assert($s[$pos] === '\\' && $pos + 2 <= strlen($s));
        $u      = $s[$pos + 1] === 'u';
        $hexLen = $u ? 4 : 8;
        assert($pos + 2 + $hexLen <= strlen($s), 'incomplete \\u or \\U escape');
        $hex = substr($s, $pos + 2, $hexLen);
        assert(preg_match('/^[0-9A-Fa-f]+$/', $hex) === 1, 'invalid hex in escape');
        $ord = (int) hexdec($hex);
        assert($ord <= 0x10FFFF, 'code point out of range');

        return mb_chr($ord, 'UTF-8');
    }

    private function decodeEchar(string $char): string
    {
        return match ($char) {
            't' => "\t",
            'b' => "\x08",
            'n' => "\n",
            'r' => "\r",
            'f' => "\f",
            '"' => '"',
            "'" => "'",
            '\\' => '\\',
            default => $char,
        };
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
