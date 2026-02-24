<?php

declare(strict_types=1);

namespace FancyRDF\Formats\TrigReader;

use FancyRDF\Streaming\StreamReader;

use function assert;
use function mb_ord;
use function ord;
use function preg_match;
use function strlen;
use function substr;

/**
 * Tokenizer for TriG / Turtle format.
 *
 * Reads from a stream and yields tokens (type + exact source slice). Whitespace and
 * comments are skipped. Invalid input is asserted; when assertions are disabled,
 * the tokenizer makes a best-effort guess so it still yields a token sequence (GIGO).
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
    private TrigTokenType $currentTokenType = TrigTokenType::EndOfInput;

    private string $currentTokenValue = '';

    public function getTokenType(): TrigTokenType
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

        return $this->currentTokenType !== TrigTokenType::EndOfInput;
    }

    /**
     * Skips whitespace and comments using only peekChar($offset).
     * Advances the stream to the next non-whitespace, non-comment character or end of input.
     */
    private function skip(): void
    {
        $offset = 0;
        while (true) {
            $ch = $this->stream->peek($offset);
            while ($ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r") {
                $offset += strlen($ch);
                $ch      = $this->stream->peek($offset);
            }

            if ($ch !== '#') {
                $this->stream->consume($offset);

                return;
            }

            while ($ch !== "\n" && $ch !== "\r" && $ch !== null) {
                $offset += strlen($ch);
                $ch      = $this->stream->peek($offset);
            }
        }
    }

    /**
     * Processes the stream to extract the next token.
     *
     * @return array{TrigTokenType, string}
     *   The token type and it's raw value.
     */
    private function process(): array
    {
        $this->skip();

        $ch = $this->stream->peek();
        if ($ch === null) {
            return [TrigTokenType::EndOfInput, ''];
        }

        // First look at unambiguous single character tokens.
        // Each of these can only start a single type of token.
        // It is thus unambiguous to return them immediately.
        foreach (
            [
                ';' => TrigTokenType::Semicolon,
                ',' => TrigTokenType::Comma,
                '[' => TrigTokenType::LSquare,
                ']' => TrigTokenType::RSquare,
                '(' => TrigTokenType::LParen,
                ')' => TrigTokenType::RParen,
                '{' => TrigTokenType::LCurly,
                '}' => TrigTokenType::RCurly,

            ] as $char => $tokenType
        ) {
            if ($ch !== $char) {
                continue;
            }

            $this->stream->consume(strlen($char));

            return [$tokenType, $char];
        }

        $iriref = $this->processIriRef();
        if ($iriref !== null) {
            return [TrigTokenType::IriRef, $iriref];
        }

        $blankNodeLabel = $this->processBlankNodeLabel();
        if ($blankNodeLabel !== null) {
            return [TrigTokenType::BlankNodeLabel, $blankNodeLabel];
        }

        if ($ch === '^') {
            $hatHat = $this->stream->consume(strlen('^^'));
            assert($hatHat === '^^', 'expected two hats');

            return [TrigTokenType::HatHat, $hatHat];
        }

        if ($ch === '"' || $ch === "'") {
            $value = $this->processString();
            if ($value !== null) {
                return [TrigTokenType::String, $value];
            }
        }

        // match @prefix, @base or a language tag
        $at = $this->processAtChar();
        if ($at !== null) {
            return [$at[0], $at[1]];
        }

        // try and match the PNAME_LN or PNAME_NS productions.
        $tok = $this->processPName();
        if ($tok !== null) {
            return $tok;
        }

        // Check for fixed-character tokens.
        foreach (
            [
                'a' => TrigTokenType::A,
                'true' => TrigTokenType::True,
                'false' => TrigTokenType::False,
            ] as $word => $token
        ) {
            if (! $this->stream->peekPrefix($word)) {
                continue;
            }

            $value = $this->stream->consume(strlen($word));

            return [$token, $value];
        }

        // check for case-insensitive keywords.
        // these are case-insensitive.
        foreach (
            [
                'GRAPH' => TrigTokenType::Graph,
                'PREFIX' => TrigTokenType::Prefix,
                'BASE' => TrigTokenType::Base,
            ] as $word => $token
        ) {
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

        return [TrigTokenType::Dot, $ch];
    }

    /**
     * Matches @prefix, @base, or a language tag (@[a-zA-Z]+(-[a-zA-Z0-9]+)*).
     * Only uses peekChar($offset); does not consume.
     *
     * @return array{TrigTokenType, string}|null
     */
    private function processAtChar(): array|null
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

        while (true) {
            $ch = $this->stream->peek($offset);
            if ($ch === null) {
                break;
            }

            if (self::isLangTagLetter($ch) || self::isDigit($ch)) {
                $offset += strlen($ch);
                continue;
            }

            if ($ch === '-') {
                $offset += strlen($ch);
                $ch      = $this->stream->peek($offset);
                if ($ch === null || (! self::isLangTagLetter($ch) && ! self::isDigit($ch))) {
                    break;
                }

                $offset += strlen($ch);
                continue;
            }

            break;
        }

        $name = '';
        $off  = 1;
        while ($off < $offset) {
            $c = $this->stream->peek($off);
            if ($c === null) {
                break;
            }

            $name .= $c;
            $off  += strlen($c);
        }

        $value = $this->stream->consume(strlen('@' . $name));
        if ($this->currentTokenType === TrigTokenType::String) {
            return [TrigTokenType::LangTag, $value];
        }

        if ($name === 'prefix') {
            return [TrigTokenType::AtPrefix, $value];
        }

        if ($name === 'base') {
            return [TrigTokenType::AtBase, $value];
        }

        // This case shouldn't happen, we just have a weird at.
        // But whatever!
        return [TrigTokenType::LangTag, $value];
    }

    /**
     * Matches any numeric literal at the start of the stream.
     * Only uses peekChar($offset); does not consume.
     *
     * @return array{TrigTokenType, int}|null
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
        $offset = 0;
        $ch     = $this->stream->peek($offset);
        if ($ch === null) {
            return null;
        }

        if ($ch === '+' || $ch === '-') {
            $offset += strlen($ch);
            $ch      = $this->stream->peek($offset);
        }

        if ($ch === null) {
            return null;
        }

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

            if ($ch === 'e' || $ch === 'E') {
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

                return [TrigTokenType::Double, $offset];
            }

            return [TrigTokenType::Decimal, $offset];
        }

        if (! self::isDigit($ch)) {
            return null;
        }

        while ($ch !== null && self::isDigit($ch)) {
            $offset += strlen($ch);
            $ch      = $this->stream->peek($offset);
        }

        if ($ch === null) {
            return [TrigTokenType::Integer, $offset];
        }

        if ($ch === 'e' || $ch === 'E') {
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

            return [TrigTokenType::Double, $offset];
        }

        if ($ch !== '.') {
            return [TrigTokenType::Integer, $offset];
        }

        $dotOffset  = $offset + strlen($ch);
        $chAfterDot = $this->stream->peek($dotOffset);
        if ($chAfterDot !== null && ! self::isDigit($chAfterDot) && $chAfterDot !== 'e' && $chAfterDot !== 'E') {
            return [TrigTokenType::Integer, $offset];
        }

        $offset          += strlen($ch);
        $ch               = $this->stream->peek($offset);
        $hasDigitAfterDot = false;
        while ($ch !== null && self::isDigit($ch)) {
            $hasDigitAfterDot = true;
            $offset          += strlen($ch);
            $ch               = $this->stream->peek($offset);
        }

        if ($ch === 'e' || $ch === 'E') {
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

            return [TrigTokenType::Double, $offset];
        }

        return $hasDigitAfterDot ? [TrigTokenType::Decimal, $offset] : null;
    }

    /**
     * Attempts to match a PNAME_LN or PNAME_NS at the current position.
     * Only uses peekChar($offset). On match, consumes the token and returns [type, value].
     *
     * @return array{TrigTokenType, string}|null [token type, consumed string] or null if no match
     */
    private function processPName(): array|null
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

        if ($ch === ':') {
            $offset += strlen($ch);
            $type    = $this->isPnLocalStart($offset) ? TrigTokenType::PnameLn : TrigTokenType::PnameNs;
            $len     = $type === TrigTokenType::PnameLn
                ? $offset + $this->pnLocalByteLength($offset)
                : $offset;

            return [$type, $this->stream->consume($len)];
        }

        if (! self::isPnCharsBase($ch)) {
            return null;
        }

        $offset       += strlen($ch);
        $lastWasPnChar = true;

        while (true) {
            $ch = $this->stream->peek($offset);
            if ($ch === null) {
                return null;
            }

            if ($ch === ':') {
                if (! $lastWasPnChar) {
                    return null;
                }

                $offset += strlen($ch);
                $type    = $this->isPnLocalStart($offset) ? TrigTokenType::PnameLn : TrigTokenType::PnameNs;
                $len     = $type === TrigTokenType::PnameLn
                    ? $offset + $this->pnLocalByteLength($offset)
                    : $offset;

                return [$type, $this->stream->consume($len)];
            }

            if ($ch === '.') {
                $lastWasPnChar = false;
                $offset       += strlen($ch);
                continue;
            }

            if (self::isPnChars($ch)) {
                $lastWasPnChar = true;
                $offset       += strlen($ch);
                continue;
            }

            return null;
        }
    }

    /**
     * Byte length of PN_LOCAL starting at $offset (does not consume).
     * PN_LOCAL cannot end with '.'; trailing '.' is not counted.
     */
    private function pnLocalByteLength(int $offset): int
    {
        $first = $this->pnLocalUnitLength($offset, true);
        if ($first === 0) {
            return 0;
        }

        $len         = $first;
        $pos         = $offset + $first;
        $lastWasDot  = false;
        $lastUnitLen = 0;

        while (($unit = $this->pnLocalUnitLength($pos, false)) !== 0) {
            $ch          = $this->stream->peek($pos);
            $lastWasDot  = ($ch === '.');
            $lastUnitLen = $unit;
            $len        += $unit;
            $pos        += $unit;
        }

        if ($lastWasDot) {
            $len -= $lastUnitLen;
        }

        return $len;
    }

    /**
     * Byte length of one PN_LOCAL unit at $offset: one char (PN_CHARS, '.', ':') or one PLX.
     * $first = true for the first unit (PN_CHARS_U | ':' | [0-9] | PLX), false for rest.
     */
    private function pnLocalUnitLength(int $offset, bool $first): int
    {
        $ch = $this->stream->peek($offset);
        if ($ch === null) {
            return 0;
        }

        if ($first) {
            if (self::isPnCharsU($ch) || $ch === ':' || self::isDigit($ch)) {
                return strlen($ch);
            }
        } else {
            if (self::isPnChars($ch) || $ch === '.' || $ch === ':') {
                return strlen($ch);
            }
        }

        $plx = $this->tryPlxLength($offset);
        if ($plx !== 0) {
            return $plx;
        }

        return 0;
    }

    /** Returns byte length of PLX at $offset, or 0 if not PLX. */
    private function tryPlxLength(int $offset): int
    {
        $ch = $this->stream->peek($offset);
        if ($ch === null) {
            return 0;
        }

        if ($ch === '%') {
            $len = strlen($ch);
            $h1  = $this->stream->peek($offset + $len);
            if ($h1 === null || ! self::isHex($h1)) {
                return 0;
            }

            $len += strlen($h1);
            $h2   = $this->stream->peek($offset + $len);
            if ($h2 === null || ! self::isHex($h2)) {
                return 0;
            }

            return $len + strlen($h2);
        }

        if ($ch === '\\') {
            $len = strlen($ch);
            $esc = $this->stream->peek($offset + $len);
            if ($esc !== null && self::isPnLocalEscChar($esc)) {
                return $len + strlen($esc);
            }
        }

        return 0;
    }

    /**
     * Consumes and returns an IRIREF from the start of the stream.
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

        $source  = $ch;
        $offset += strlen($ch);

        while (true) {
            $ch = $this->stream->peek($offset);
            if ($ch === null || $ch === '>') {
                if ($ch !== null) {
                    $source .= $ch;
                    $offset += strlen($ch);
                }

                break;
            }

            $source .= $ch;
            $offset += strlen($ch);

            if ($ch !== '\\') {
                continue;
            }

            $next = $this->stream->peek($offset);
            if ($next !== 'u' && $next !== 'U') {
                continue;
            }

            $source .= $next;
            $offset += strlen($next);

            $hexLen             = $next === 'u' ? 4 : 8;
            [$offset, $hexPart] = $this->consumeUcharAt($offset, $hexLen);
            $source            .= $hexPart;
        }

        $this->stream->consume($offset);

        return $source;
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
     * Matches a string literal at the current position using only peekChar($offset).
     * Reuses consumeUcharAt for \u and \U escapes. Does not consume on failure.
     *
     * @return string|null Consumed string (including delimiters), or null if no match (nothing consumed)
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

        $next   = $this->stream->peek($offset);
        $isLong = false;
        if ($next === $delim) {
            $offset += strlen($next);
            $next2   = $this->stream->peek($offset);
            if ($next2 === $delim) {
                $offset += strlen($next2);
                $isLong  = true;
            }
        }

        if (! $isLong && $offset === 2) {
            return $this->stream->consume($offset);
        }

        if ($isLong) {
            $end    = $delim . $delim . $delim;
            $source = $end;
            while (true) {
                $ch = $this->stream->peek($offset);
                if ($ch === null) {
                    return null;
                }

                $source .= $ch;
                $offset += strlen($ch);
                $pos     = strlen($source);
                if ($pos < 6 || substr($source, -3) !== $end) {
                    continue;
                }

                $backslashes = 0;
                $i           = $pos - 4;
                while ($i >= 0 && $source[$i] === '\\') {
                    $backslashes++;
                    $i--;
                }

                if ($backslashes % 2 === 0) {
                    break;
                }
            }

            return $this->stream->consume($offset);
        }

        while (true) {
            $ch = $this->stream->peek($offset);
            if ($ch === null) {
                return null;
            }

            if ($ch === $delim) {
                $offset += strlen($ch);
                break;
            }

            if ($ch === '\\') {
                $offset += strlen($ch);
                $next    = $this->stream->peek($offset);
                if ($next !== null) {
                    $offset += strlen($next);
                    if ($next === 'u' || $next === 'U') {
                        $hexLen   = $next === 'u' ? 4 : 8;
                        [$offset] = $this->consumeUcharAt($offset, $hexLen);
                    }
                }

                continue;
            }

            $offset += strlen($ch);
        }

        return $this->stream->consume($offset);
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
        $offset = 0;
        $ch     = $this->stream->peek($offset);
        if ($ch !== '_') {
            return null;
        }

        $offset += strlen($ch);
        $ch      = $this->stream->peek($offset);
        if ($ch !== ':') {
            return null;
        }

        $offset += strlen($ch);
        $ch      = $this->stream->peek($offset);
        if ($ch === null || (! self::isPnCharsU($ch) && ! self::isDigit($ch))) {
            return null;
        }

        $offset        += strlen($ch);
        $lastWasPnChar  = true;
        $lastDotByteLen = 0;

        while (true) {
            $ch = $this->stream->peek($offset);
            if ($ch === null) {
                break;
            }

            if (self::isPnChars($ch)) {
                $lastWasPnChar = true;
                $offset       += strlen($ch);
                continue;
            }

            if ($ch === '.') {
                $lastWasPnChar  = false;
                $lastDotByteLen = strlen($ch);
                $offset        += $lastDotByteLen;
                continue;
            }

            break;
        }

        if (! $lastWasPnChar) {
            $offset -= $lastDotByteLen;
        }

        return $this->stream->consume($offset);
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

    /** Whether there is a PN_LOCAL at $offset (first char of local part). */
    private function isPnLocalStart(int $offset): bool
    {
        $ch = $this->stream->peek($offset);
        if ($ch === null) {
            return false;
        }

        if (self::isPnCharsU($ch) || $ch === ':' || self::isDigit($ch)) {
            return true;
        }

        return $this->tryPlxLength($offset) !== 0;
    }

    private static function isPnCharsBase(string $ch): bool
    {
        if ($ch === '') {
            return false;
        }

        // [163s]   PN_CHARS_BASE   ::= [A-Z] | [a-z] | [#00C0-#00D6] | [#00D8-#00F6] | [#00F8-#02FF] | [#0370-#037D] | [#037F-#1FFF] | [#200C-#200D] | [#2070-#218F] | [#2C00-#2FEF] | [#3001-#D7FF] | [#F900-#FDCF] | [#FDF0-#FFFD] | [#10000-#EFFFF]
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

        // [166s]   PN_CHARS    ::= PN_CHARS_U | '-' | [0-9] | #00B7 | [#0300-#036F] | [#203F-#2040]

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
        if ($ch === '' || strlen($ch) !== 1) {
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
