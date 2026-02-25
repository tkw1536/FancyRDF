<?php

declare(strict_types=1);

namespace FancyRDF\Formats\TrigReader;

/**
 * Token types for the TriG / Turtle lexical grammar.
 *
 * @see https://www.w3.org/TR/trig/
 * @see https://www.w3.org/TR/turtle/
 */
enum TrigToken: string
{
    // Keywords (case-sensitive)
    // These always have themselves as a value.
    case AtPrefix = '@prefix';
    case AtBase   = '@base';
    case A        = 'a';
    case True     = 'true';
    case False    = 'false';

    // Keywords (case-insensitive): GRAPH, PREFIX, BASE
    // These always have themselves as a value.
    case Graph  = 'GRAPH';
    case Prefix = 'PREFIX'; // or '@base'
    case Base   = 'BASE'; // or '@prefix'

    // Punctuation
    // These always have themselves as a value.
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

    // Literals / terms
    case IriRef         = 'IRIREF';
    case PnameNs        = 'PNAME_NS';
    case PnameLn        = 'PNAME_LN';
    case BlankNodeLabel = 'BLANK_NODE_LABEL';

    // String (all four string productions)
    case String = 'STRING';

    // Numbers
    case Integer = 'INTEGER';
    case Decimal = 'DECIMAL';
    case Double  = 'DOUBLE';

    // Other
    case LangTag = 'LANGTAG';

    /**
     * End of input was reached.
     *
     * Always has an empty value.
     */
    case EndOfInput = '';
}
