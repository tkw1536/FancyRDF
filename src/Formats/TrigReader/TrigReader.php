<?php

declare(strict_types=1);

namespace FancyRDF\Formats\TrigReader;

use FancyRDF\Streaming\StreamReader;

use function array_key_exists;
use function assert;
use function hexdec;
use function is_string;
use function mb_chr;
use function mb_ord;
use function ord;
use function preg_match;
use function str_ends_with;
use function strlen;
use function substr;

/**
 * Tokenizer for TriG / Turtle format.
 *
 * Reads from a stream and yields tokens (type + decoded value where applicable).
 * Whitespace and comments are skipped. Invalid input is asserted; when assertions
 * are disabled, the tokenizer makes a best-effort guess so it still yields a token
 * sequence (GIGO).
 *
 * The client must call next() at least once before getTokenType() / getTokenValue().
 * Initially, after the first next() that returns true, the current token is set;
 * when next() returns false, the current token is EndOfInput and getTokenValue() is ''.
 *
 * @see https://www.w3.org/TR/trig/
 * @see https://www.w3.org/TR/turtle/
 */
final class TrigReader
{
    private TrigToken $currentTokenType = TrigToken::EndOfInput;

    private string $currentTokenValue = '';

    public function getTokenType(): TrigToken
    {
        return $this->currentTokenType;
    }

    public function getTokenValue(): string
    {
        return $this->currentTokenValue;
    }

    public function __construct(
        public readonly StreamReader $stream,
    ) {
    }

    public function next(): bool
    {
        [$this->currentTokenType, $this->currentTokenValue] = $this->process();

        return $this->currentTokenType !== TrigToken::EndOfInput;
    }

    /**
     * Skips whitespace and comments until one of the following is true:
     *
     * - we have a non-whitespace, non-comment character next in the stream.
     * - we have reached the end of the stream.
     */
    private function skip(): void
    {
        $offset = 0;

        $ch = $this->stream->peek($offset);
        while ($ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r") {
            $offset++;
            $ch = $this->stream->peek($offset);
        }

        // While we have a comment, skip until the next line is reached
        // and then skip whitespace again.
        while ($ch === '#') {
            while ($ch !== "\n" && $ch !== "\r" && $ch !== null) {
                $offset++;
                $ch = $this->stream->peek($offset);
            }

            $ch = $this->stream->peek($offset);
            while ($ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r") {
                $offset++;
                $ch = $this->stream->peek($offset);
            }
        }

        // Consume everything we skipped.
        $this->stream->consume($offset);
    }

    private const array PUNCTUATION_TOKENS = [
        ';' => TrigToken::Semicolon,
        ',' => TrigToken::Comma,
        '[' => TrigToken::LSquare,
        ']' => TrigToken::RSquare,
        '(' => TrigToken::LParen,
        ')' => TrigToken::RParen,
        '{' => TrigToken::LCurly,
        '}' => TrigToken::RCurly,
    ];

    private const array CASE_SENSITIVE_KEYWORDS = [
        'a' => TrigToken::A,
        'true' => TrigToken::True,
        'false' => TrigToken::False,
    ];

    private const array CASE_INSENSITIVE_KEYWORDS = [
        'GRAPH' => TrigToken::Graph,
        'PREFIX' => TrigToken::Prefix,
        'BASE' => TrigToken::Base,
    ];

    /**
     * Consumes the next token from the stream.
     *
     * @return array{TrigToken, string}
     *   The token type and its (decoded) value.
     */
    private function process(): array
    {
        $this->skip();

        $ch = $this->stream->peek();
        if ($ch === null) {
            return [TrigToken::EndOfInput, ''];
        }

        // First look at unambiguous punctuation tokens.
        // Each of these can only start a single type of token.
        if (array_key_exists($ch, self::PUNCTUATION_TOKENS)) {
            $this->stream->consume(strlen($ch));

            return [self::PUNCTUATION_TOKENS[$ch], $ch];
        }

        $iriref = $this->processIriRef();
        if ($iriref !== null) {
            return [TrigToken::IriRef, $iriref];
        }

        $blankNodeLabel = $this->processBlankNodeLabel();
        if ($blankNodeLabel !== null) {
            return [TrigToken::BlankNodeLabel, $blankNodeLabel];
        }

        if ($ch === '^') {
            $hatHat = $this->stream->consume(strlen('^^'));
            assert($hatHat === '^^', 'expected two hats');

            return [TrigToken::HatHat, $hatHat];
        }

        // match string literals
        $string = $this->processString();
        if ($string !== null) {
            return [TrigToken::String, $string];
        }

        // match @prefix, @base or a language tag
        $at = $this->processAtChar();
        if ($at !== null) {
            return [TrigToken::AtKeyword, $at];
        }

        // try and match the PNAME_LN or PNAME_NS productions.
        $tok = $this->processPName();
        if ($tok !== null) {
            return [
                str_ends_with($tok, ':') ? TrigToken::PnameNs : TrigToken::PnameLn,
                $tok,
            ];
        }

        // first check for case-sensitive keywords.
        foreach (self::CASE_SENSITIVE_KEYWORDS as $word => $token) {
            if (! $this->stream->peekPrefix($word)) {
                continue;
            }

            $value = $this->stream->consume(strlen($word));

            return [$token, $value];
        }

        // now check for case-insensitive keywords.
        foreach (self::CASE_INSENSITIVE_KEYWORDS as $word => $token) {
            if (! $this->stream->peekPrefix($word, true)) {
                continue;
            }

            $value = $this->stream->consume(strlen($word));

            return [$token, $value];
        }

        $numeric = $this->matchNumericLiteral();
        if ($numeric !== null) {
            [$token, $len] = $numeric;
            $res           = $this->stream->consume($len);

            return [$token, $res];
        }

        $this->stream->consume(strlen($ch));
        assert($ch === '.', 'expected dot, got ' . $ch);

        return [TrigToken::Dot, $ch];
    }

    /**
     * Matches @prefix, @base, or a language tag (@[a-zA-Z]+(-[a-zA-Z0-9]+)*).
     * Only uses peekChar($offset); does not consume.
     */
    private function processAtChar(): string|null
    {
        $offset = 0;
        $ch     = $this->stream->peek($offset);
        if ($ch !== '@') {
            return null;
        }

        $offset += strlen($ch);
        $ch      = $this->stream->peek($offset);
        if ($ch === null || ! self::isLangTagLetter($ch)) {
            return null;
        }

        $offset += strlen($ch);

        $hyphenPos       = null;
        $charAfterHyphen = false;
        while (true) {
            $ch = $this->stream->peek($offset);
            if ($ch === null) {
                break;
            }

            if (self::isLangTagLetter($ch) || self::isDigit($ch)) {
                $charAfterHyphen = $hyphenPos !== null;
                $offset         += strlen($ch);
                continue;
            }

            if ($hyphenPos === null && $ch === '-') {
                $hyphenPos = $offset;
                $offset   += strlen($ch);
                continue;
            }

            break;
        }

        if ($hyphenPos !== null && ! $charAfterHyphen) {
            $offset = $hyphenPos;
        }

        $name = $this->stream->consume($offset);
        $name = substr($name, 1);

        return $name;
    }

    /**
     * Matches any numeric literal at the start of the stream.
     * Only uses peekChar($offset); does not consume.
     *
     * @return array{TrigToken, int}|null
     *   The token type and the byte length of the matched numeric literal.
     *   Null if no match.
     *
     * [20] INTEGER ::= [+-]? [0-9]+
     * [21] DECIMAL ::= [+-]? ([0-9]* '.' [0-9]+)
     * [22] DOUBLE ::= [+-]? ([0-9]+ '.' [0-9]* EXPONENT | '.' [0-9]+ EXPONENT | [0-9]+ EXPONENT)
     * [154s] EXPONENT ::= [eE] [+-]? [0-9]+
     */
    private function matchNumericLiteral(): array|null
    {
        // INTEGER: [+-]?[0-9]+
        // DECIMAL: [+-]?[0-9]*\.[0-9]+
        // DOUBLE:  [+-]?([0-9]+\.[0-9]|\.[0-9]+|[0-9])[eE][+-]?[0-9]+

        $offset = 0;
        $ch     = $this->stream->peek($offset);
        if ($ch === null) {
            return null;
        }

        // Optional leading "+" or "-"
        if ($ch === '+' || $ch === '-') {
            $offset += strlen($ch);
            $ch      = $this->stream->peek($offset);
        }

        if ($ch === null) {
            return null;
        }

        // Case 1: ".digits" (decimal or double)
        if ($ch === '.') {
            $offset += strlen($ch);
            $ch      = $this->stream->peek($offset);
            if ($ch === null || ! self::isDigit($ch)) {
                return null;
            }

            while ($ch !== null && self::isDigit($ch)) {
                $offset += strlen($ch);
                $ch      = $this->stream->peek($offset);
            }

            $expOffset = $this->gobbleExponentAt($offset);

            return $expOffset !== null ? [TrigToken::Double, $expOffset] : [TrigToken::Decimal, $offset];
        }

        // From here on we must start with a digit.
        if (! self::isDigit($ch)) {
            return null;
        }

        // Read integer part.
        while ($ch !== null && self::isDigit($ch)) {
            $offset += strlen($ch);
            $ch      = $this->stream->peek($offset);
        }

        if ($ch === null) {
            return [TrigToken::Integer, $offset];
        }

        // Possible exponent directly after integer.
        $expOffset = $this->gobbleExponentAt($offset);
        if ($expOffset !== null) {
            return [TrigToken::Double, $expOffset];
        }

        // Case 2: digits '.' [digits] [EXPONENT]
        if ($ch !== '.') {
            return [TrigToken::Integer, $offset];
        }

        $dotOffset  = $offset + strlen($ch);
        $chAfterDot = $this->stream->peek($dotOffset);
        if ($chAfterDot !== null && ! self::isDigit($chAfterDot) && $chAfterDot !== 'e' && $chAfterDot !== 'E') {
            return [TrigToken::Integer, $offset];
        }

        $offset          += strlen($ch);
        $ch               = $this->stream->peek($offset);
        $hasDigitAfterDot = false;
        while ($ch !== null && self::isDigit($ch)) {
            $hasDigitAfterDot = true;
            $offset          += strlen($ch);
            $ch               = $this->stream->peek($offset);
        }

        $expOffset = $this->gobbleExponentAt($offset);

        return match (true) {
            $expOffset !== null => [TrigToken::Double, $expOffset],
            $hasDigitAfterDot => [TrigToken::Decimal, $offset],
            default => null,
        };
    }

    /**
     * Matches EXPONENT at $offset (starting at optional 'e'/'E').
     *
     * @return int|null New offset after the exponent, or null if no valid exponent at $offset.
     */
    private function gobbleExponentAt(int $offset): int|null
    {
        $ch = $this->stream->peek($offset);
        if ($ch !== 'e' && $ch !== 'E') {
            return null;
        }

        $offset += strlen($ch);
        $ch      = $this->stream->peek($offset);

        if ($ch === '+' || $ch === '-') {
            $offset += strlen($ch);
            $ch      = $this->stream->peek($offset);
        }

        if ($ch === null || ! self::isDigit($ch)) {
            return null;
        }

        while ($ch !== null && self::isDigit($ch)) {
            $offset += strlen($ch);
            $ch      = $this->stream->peek($offset);
        }

        return $offset;
    }

    /**
     * Attempts to match a PNAME_LN or PNAME_NS at the current position.
     * Builds the decoded value (prefix ':' local with local unescaped) as it goes.
     *
     * @return string|null [token type, decoded value] or null if no match
     */
    private function processPName(): string|null
    {
        // [139s] PNAME_NS ::= PN_PREFIX? ':'
        // [140s] PNAME_LN ::= PNAME_NS PN_LOCAL
        // [167s] PN_PREFIX ::= PN_CHARS_BASE ((PN_CHARS | '.')* PN_CHARS)?
        // [168s] PN_LOCAL  ::= (PN_CHARS_U | ':' | [0-9] | PLX) ((PN_CHARS | '.' | ':' | PLX)* (PN_CHARS | ':' | PLX))?
        $offset = 0;
        $ch     = $this->stream->peek($offset);
        if ($ch === null) {
            return null;
        }

        // Read the optional PN_PREFIX PRODUCTION.
        $value = '';
        if ($ch !== ':') {
            if (! self::isPnCharsBase($ch)) {
                return null;
            }

            // Read valid characters until the colon.
            $lastWasDot = false;
            $ch         = $this->stream->peek($offset);
            while (
                $ch !== null &&
                $ch !== ':' &&
                ($ch === '.' || self::isPnChars($ch))
            ) {
                $value     .= $ch;
                $lastWasDot = $ch === '.';
                $offset    += strlen($ch);

                $ch = $this->stream->peek($offset);
            }

            // If we exited the loop above because of an invalid character
            // or the last character we saw was a dot, return null.
            if ($ch !== ':' || $lastWasDot) {
                return null;
            }
        }

        $offset += strlen($ch);

        // Now read the PN_LOCAL production after the token.

        $value .= ':';
        $first  = true;

        $lastWasDot = false;
        while (true) {
            $unit = $this->readPnLocalUnit($offset, $first);
            if ($unit === null) {
                break;
            }

            [$offset, $fragment] = $unit;
            $value              .= $fragment;
            $lastWasDot          = $fragment === '.';
            $first               = false;
        }

        if ($lastWasDot) {
            $offset--;
            $value = substr($value, 0, -1);
        }

        $this->stream->consume($offset);

        return $value;
    }

    /**
     * Reads one PN_LOCAL unit at $offset (one char or one PLX); returns decoded fragment.
     *
     * @return array{int, string}|null [new offset, decoded fragment] or null if no unit
     */
    private function readPnLocalUnit(int $offset, bool $first): array|null
    {
        $ch = $this->stream->peek($offset);
        if ($ch === null) {
            return null;
        }

        // PLX: backslash escape
        if ($ch === '\\') {
            $esc = $this->stream->peek($offset + strlen($ch));
            if ($esc === null || ! self::isPnLocalEscChar($esc)) {
                return null;
            }

            return [$offset + strlen($ch) + strlen($esc), $esc];
        }

        // PLX: percent encoding % HEX HEX
        if ($ch === '%') {
            $h1 = $this->stream->peek($offset + strlen($ch));
            $h2 = $h1 === null ? null : $this->stream->peek($offset + strlen($ch) + strlen($h1));
            if ($h1 === null || $h2 === null || ! self::isHex($h1) || ! self::isHex($h2)) {
                return null;
            }

            $fragment = $ch . $h1 . $h2;
            $newOff   = $offset + strlen($ch) + strlen($h1) + strlen($h2);

            return [$newOff, $fragment];
        }

        // Non-PLX unit: one char depending on position (first vs rest).
        if ($first) {
            if (! self::isPnCharsU($ch) && $ch !== ':' && ! self::isDigit($ch)) {
                return null;
            }
        } else {
            if (! self::isPnChars($ch) && $ch !== '.' && $ch !== ':') {
                return null;
            }
        }

        return [$offset + strlen($ch), $ch];
    }

    /**
     * Consumes and returns an IRIREF from the start of the stream (decoded).
     *
     * If there isn't any IRIREF, returns null.
     */
    private function processIriRef(): string|null
    {
        // [19] IRIREF ::= '<' ([^#x00-#x20<>"{}|^`\] | UCHAR)* '>'
        // [27] UCHAR  ::= '\u' HEX HEX HEX HEX | '\U' HEX HEX HEX HEX HEX HEX HEX HEX
        // [171s] HEX  ::= [0-9] | [A-F] | [a-f]

        $offset = 0;
        $ch     = $this->stream->peek($offset);
        if ($ch !== '<') {
            return null;
        }

        $offset += strlen($ch);
        $result  = '';

        while (true) {
            $ch = $this->stream->peek($offset);
            if ($ch === null || $ch === '>') {
                if ($ch !== null) {
                    $offset += strlen($ch);
                }

                break;
            }

            if ($ch === '\\') {
                $offset += strlen($ch);
                $next    = $this->stream->peek($offset);
                if ($next !== 'u' && $next !== 'U') {
                    $result .= $ch;
                    continue;
                }

                $offset            += strlen($next);
                $hexLen             = $next === 'u' ? 4 : 8;
                [$offset, $decoded] = $this->decodeUcharAtOffset($offset, $hexLen);
                $result            .= $decoded;
                continue;
            }

            $result .= $ch;
            $offset += strlen($ch);
        }

        $this->stream->consume($offset);

        return $result;
    }

    /**
     * Reads $hexLen hex digits at $offset using only peekChar($offset).
     *
     * @return array{int, string} [new offset after the hex digits, string of hex chars read]
     */
    private function consumeUcharAt(int $offset, int $hexLen): array
    {
        $hexPart = '';
        for ($i = 0; $i < $hexLen; $i++) {
            $c = $this->stream->peek($offset);
            if ($c === null) {
                break;
            }

            $hexPart .= $c;
            $offset  += strlen($c);
        }

        assert(strlen($hexPart) === $hexLen, 'incomplete \\u or \\U escape');

        return [$offset, $hexPart];
    }

    /**
     * Decodes a UCHAR at $offset (offset points at first hex digit after \u or \U).
     *
     * @return array{int, string} [new offset after the hex digits, decoded character]
     */
    private function decodeUcharAtOffset(int $offset, int $hexLen): array
    {
        [$newOffset, $hexPart] = $this->consumeUcharAt($offset, $hexLen);

        return [$newOffset, $this->hexPartToDecodedChar($hexPart)];
    }

    private function hexPartToDecodedChar(string $hex): string
    {
        assert(preg_match('/^[0-9A-Fa-f]+$/', $hex) === 1, 'invalid hex in escape');
        $ord = (int) @hexdec($hex);
        assert(
            $ord <= 0x10FFFF &&
            ($ord < 0xD800 || $ord > 0xDFFF),
            'code point out of range',
        );

        $res = mb_chr($ord, 'UTF-8');

        /* @phpstan-ignore function.alreadyNarrowedType (if the assertions do not hold this is wrong) */
        return is_string($res) ? $res : '';
    }

    /**
     * Matches a string literal at the current position; returns decoded content only.
     * Builds the decoded string as it goes. Does not consume on failure.
     *
     * @return string|null
     *     Decoded string content, or null if there isn't a string literal.
     */
    private function processString(): string|null
    {
        $offset = 0;
        $ch     = $this->stream->peek($offset);
        if ($ch === null || ($ch !== '"' && $ch !== "'")) {
            return null;
        }

        $delim   = $ch;
        $offset += strlen($ch);

        // Check if we have a long string with ''' or """ characters.
        $isLong = false;
        if ($this->stream->peek($offset) === $delim && $this->stream->peek($offset + 1) === $delim) {
            $offset += 2;
            $isLong  = true;
        }

        return $this->decodeStringBody($offset, $delim, $isLong);
    }

    /**
     * Decodes the body of a string literal starting at $offset (after opening quotes).
     *
     * @return string Decoded string content
     */
    private function decodeStringBody(int $offset, string $delim, bool $isLong): string
    {
        $result = '';

        $unexpectedEOF = false;
        while (true) {
            $ch = $this->stream->peek($offset);
            if ($ch === null) {
                $unexpectedEOF = true;
                break;
            }

            if ($ch === '\\') {
                $offset += strlen($ch);
                $next    = $this->stream->peek($offset);
                if ($next === null) {
                    $unexpectedEOF = true;
                    break;
                }

                if ($next === 'u' || $next === 'U') {
                    $offset            += strlen($next);
                    $hexLen             = $next === 'u' ? 4 : 8;
                    [$offset, $decoded] = $this->decodeUcharAtOffset($offset, $hexLen);
                    $result            .= $decoded;
                } else {
                    $result .= $this->decodeEchar($next);
                    $offset += strlen($next);
                }

                continue;
            }

            if ($ch === $delim) {
                // Short string: stop at single delimiter.
                if (! $isLong) {
                    $offset += 1;
                    break;
                }

                // Long string: '''...''' or """...""" end only on triple delimiter.
                if (
                    $this->stream->peek($offset + 1) === $delim &&
                    $this->stream->peek($offset + 2) === $delim
                ) {
                    $offset += 3;
                    break;
                }
            }

            $result .= $ch;
            $offset += strlen($ch);
        }

        assert(! $unexpectedEOF, 'unexpected end of string');
        $this->stream->consume($offset);

        return $result;
    }

    /**
     * Matches BLANK_NODE_LABEL at the current position using only peekChar($offset).
     * Reuses PN_CHARS helpers from processPName. Does not consume on failure.
     *
     * [141s] BLANK_NODE_LABEL ::= '_:' (PN_CHARS_U | [0-9]) ((PN_CHARS | '.')* PN_CHARS)?
     *
     * @return string|null Consumed blank node label string, or null if no match (nothing consumed)
     */
    private function processBlankNodeLabel(): string|null
    {
        if (! $this->stream->peekPrefix('_:')) {
            return null;
        }

        $offset = 2; // strlen('_:')

        $ch = $this->stream->peek($offset);
        if ($ch === null || (! self::isPnCharsU($ch) && ! self::isDigit($ch))) {
            return null;
        }

        $offset     += strlen($ch);
        $trailingDot = null;

        while (true) {
            $ch = $this->stream->peek($offset);
            if ($ch === null) {
                break;
            }

            if (self::isPnChars($ch)) {
                $trailingDot = false;
                $offset     += strlen($ch);
                continue;
            }

            if ($ch === '.') {
                $trailingDot = true;
                $offset     += strlen($ch);
                continue;
            }

            break;
        }

        if ($trailingDot) {
            $offset--;
        }

        $raw   = $this->stream->consume($offset);
        $label = substr($raw, 2);

        if (str_ends_with($label, '.')) {
            $label = substr($label, 0, -1);
        }

        return $label;
    }

    // ================================
    // Decoding helpers
    // ================================

    /** @param non-empty-string $char */
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

    // ================================
    // Character classes
    // ================================

    /** is character a digit? */
    private static function isDigit(string $ch): bool
    {
        return $ch !== '' && strlen($ch) === 1 && ord($ch[0]) >= 0x30 && ord($ch[0]) <= 0x39;
    }

    /** is the character a lang tag letter? */
    private static function isLangTagLetter(string $ch): bool
    {
        if ($ch === '' || strlen($ch) !== 1) {
            return false;
        }

        $o = ord($ch[0]);

        return ($o >= 0x41 && $o <= 0x5A) || ($o >= 0x61 && $o <= 0x7A);
    }

    private static function isPnCharsBase(string $ch): bool
    {
        if ($ch === '') {
            return false;
        }

        $cp = mb_ord($ch, 'UTF-8');

        if ($cp <= 0x7F) {
            return ($cp >= 0x41 && $cp <= 0x5A) || ($cp >= 0x61 && $cp <= 0x7A);
        }

        $ranges = [
            [0x00C0, 0x00D6],
            [0x00D8, 0x00F6],
            [0x00F8, 0x02FF],
            [0x0370, 0x037D],
            [0x037F, 0x1FFF],
            [0x200C, 0x200D],
            [0x2070, 0x218F],
            [0x2C00, 0x2FEF],
            [0x3001, 0xD7FF],
            [0xF900, 0xFDCF],
            [0xFDF0, 0xFFFD],
            [0x10000, 0xEFFFF],
        ];
        foreach ($ranges as [$lo, $hi]) {
            if ($cp >= $lo && $cp <= $hi) {
                return true;
            }
        }

        return false;
    }

    private static function isPnCharsU(string $ch): bool
    {
        return $ch === '_' || self::isPnCharsBase($ch);
    }

    private static function isPnChars(string $ch): bool
    {
        if ($ch === '') {
            return false;
        }

        if (self::isPnCharsU($ch) || $ch === '-' || self::isDigit($ch)) {
            return true;
        }

        $cp = mb_ord($ch, 'UTF-8');

        return $cp === 0x00B7
            || ($cp >= 0x0300 && $cp <= 0x036F)
            || ($cp >= 0x203F && $cp <= 0x2040);
    }

    private static function isHex(string $ch): bool
    {
        if (strlen($ch) !== 1) {
            return false;
        }

        $o = ord($ch[0]);

        return ($o >= 0x30 && $o <= 0x39) || ($o >= 0x41 && $o <= 0x46) || ($o >= 0x61 && $o <= 0x66);
    }

    private static function isPnLocalEscChar(string $ch): bool
    {
        return $ch !== '' && preg_match("/^[_~.\\-!\\\$&'()*+,;=\\/?#@%]$/u", $ch) === 1;
    }
}
