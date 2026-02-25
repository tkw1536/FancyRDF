<?php

declare(strict_types=1);

namespace FancyRDF\Formats\TrigReader;

/**
 * Token types for the TriG / Turtle lexical grammar.
 *
 * Value semantics (getTokenValue() from TrigReader):
 *
 * - Literal value (exact source): Dot, Semicolon, Comma, LSquare, RSquare, LParen, RParen,
 *   LCurly, RCurly, HatHat, A, True, False, Graph, Prefix, Base. For punctuation and
 *   case-sensitive keywords the value is the token as in source; for Graph/Prefix/Base
 *   the value is the actual casing from input.
 *
 * - Decoded content: IriRef → inner IRI (no angle brackets, UCHAR-unescaped). String →
 *   string content only (no delimiters, ECHAR/UCHAR-unescaped). BlankNodeLabel → label
 *   only (no "_:", trailing "." removed). PnameNs / PnameLn → "prefix:local" with local
 *   part backslash-escapes resolved. Integer, Decimal, Double → lexical form as in source.
 *
 * - AtKeyword → "prefix" when source was @prefix, "base" when source was @base, or the
 *   language tag without the leading '@' (e.g. "en", "en-US").
 *
 * - EndOfInput → always ''.
 *
 * @see https://www.w3.org/TR/trig/
 * @see https://www.w3.org/TR/turtle/
 */
enum TrigToken: string
{
    /** Value: "prefix" | "base" | or language tag without '@' (e.g. "en"). */
    case AtKeyword = 'AT';

    // Literal value (token as in source)
    case A         = 'a';
    case True      = 'true';
    case False     = 'false';
    case Graph     = 'GRAPH';
    case Prefix    = 'PREFIX';
    case Base      = 'BASE';
    case Dot       = '.';
    case Semicolon = ';';
    case Comma     = ',';
    case LSquare   = '[';
    case RSquare   = ']';
    case LParen    = '(';
    case RParen    = ')';
    case LCurly    = '{';
    case RCurly    = '}';
    case HatHat    = '^^';

    /** Value: inner IRI, unescaped (no <>s). */
    case IriRef = 'IRIREF';

    /** Value: "prefix:" with local part unescaped. */
    case PnameNs = 'PNAME_NS';

    /** Value: "prefix:local" with local part unescaped. */
    case PnameLn = 'PNAME_LN';

    /** Value: label only (no "_:", trailing "." removed). */
    case BlankNodeLabel = 'BLANK_NODE_LABEL';

    /** Value: string content only, unescaped (no delimiters). */
    case String = 'STRING';

    // Lexical form as in source
    case Integer = 'INTEGER';
    case Decimal = 'DECIMAL';
    case Double  = 'DOUBLE';

    /** Value: always "". */
    case EndOfInput = '';
}
