<?php

declare(strict_types=1);

namespace FancyRDF\Formats\TrigReader;

use FancyRDF\Streaming\BufferedStream;

use function assert;
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
    private readonly BufferedStream $stream;

    private TrigTokenType $currentTokenType = TrigTokenType::EndOfInput;

    private string $currentTokenValue = '';

    /** @param resource $source Stream to read from (e.g. from fopen). */
    public function __construct(
        mixed $source,
        int $readChunkSize = BufferedStream::DEFAULT_READ_CHUNK_SIZE,
    ) {
        $this->stream = new BufferedStream($source, $readChunkSize);
    }

    public function next(): bool
    {
        $this->skipWhitespaceAndComments();
        $ch = $this->stream->peekChar();
        if ($ch === null) {
            $this->currentTokenType  = TrigTokenType::EndOfInput;
            $this->currentTokenValue = '';

            return false;
        }

        [$type, $value]          = $this->processToken($ch);
        $this->currentTokenType  = $type;
        $this->currentTokenValue = $value;

        // everything that we have eaten.
        $this->stream->flush();

        return true;
    }

    public function getTokenType(): TrigTokenType
    {
        return $this->currentTokenType;
    }

    public function getTokenValue(): string
    {
        return $this->currentTokenValue;
    }

    // ================================
    // Token recognition
    // ================================

    /**
     * Skips whitespace and comments.
     *
     * This method will advance the buffer position to the next non-whitespace, non-comment character or end of input.
     */
    private function skipWhitespaceAndComments(): void
    {
        $ch = $this->stream->peekChar();
        while (true) {
            // skip pure whitespace
            while ($ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r") {
                $this->stream->eatChar($ch);
                $ch = $this->stream->peekChar();
            }

            // if there is a comment, skip until end of line.
            // if there isn't a comment, we have reached end-of-input.
            if ($ch !== '#') {
                return;
            }

            while ($ch !== "\n" && $ch !== "\r" && $ch !== null) {
                $this->stream->eatChar($ch);
                $ch = $this->stream->peekChar();
            }
        }
    }

    /**
     * Processes the stream to extract the next token.
     *
     * @return array{TrigTokenType, string}
     *   The token type and it's raw value.
     */
    private function processToken(string $ch): array
    {
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

            $this->stream->eatString($char);

            return [$tokenType, $char];
        }

        if ($ch === '<') {
            $value = $this->consumeIriRef();

            return [TrigTokenType::IriRef, $value];
        }

        if ($ch === '_') {
            $value = $this->consumeBlankNodeLabel();

            return [TrigTokenType::BlankNodeLabel, $value];
        }

        if ($ch === '^') {
            $hatHat = $this->stream->eatString('^^');
            assert($hatHat === '^^', 'expected two hats');

            return [TrigTokenType::HatHat, $hatHat];
        }

        if ($ch === '"' || $ch === "'") {
            $value = $this->consumeString();

            return [TrigTokenType::String, $value];
        }

        // match @prefix, @base or a language tag
        $at = $this->matchAt();
        if ($at !== null) {
            return [$at[0], $at[1]];
        }

        // try and match the PNAME_LN or PNAME_NS productions.
        $tok = $this->matchPName();
        if ($tok !== null) {
            [$token, $len] = $tok;
            $value         = $this->stream->eatString($len);

            return [$token, $value];
        }

        // Check for fixed-character tokens.
        foreach (
            [
                'a' => TrigTokenType::A,
                'true' => TrigTokenType::True,
                'false' => TrigTokenType::False,
            ] as $word => $token
        ) {
            if (! $this->stream->startsWith($word)) {
                continue;
            }

            $value = $this->stream->eatString($word);

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
            if (! $this->stream->startsWith($word, true)) {
                continue;
            }

            $value = $this->stream->eatString($word);

            return [$token, $value];
        }

        $numeric = $this->matchNumericLiteral();
        if ($numeric !== null) {
            [$token, $len] = $numeric;
            $res           = $this->stream->eatString($len);

            return [$token, $res];
        }

        $this->stream->eatChar($ch);
        assert($ch === '.', 'expected dot, got ' . $ch);

        return [TrigTokenType::Dot, $ch];
    }

    /**
     * Matches @prefix, @base, or a language tag (@[a-zA-Z]+(-[a-zA-Z0-9]+)*).
     * Only uses peekChar($offset); does not consume.
     *
     * @return array{TrigTokenType, string}|null
     */
    private function matchAt(): array|null
    {
        $offset = 0;
        $ch     = $this->stream->peekChar($offset);
        if ($ch !== '@') {
            return null;
        }

        $offset += strlen($ch);
        $ch      = $this->stream->peekChar($offset);
        if ($ch === null || ! self::isLangTagLetter($ch)) {
            return null;
        }

        $offset += strlen($ch);

        while (true) {
            $ch = $this->stream->peekChar($offset);
            if ($ch === null) {
                break;
            }

            if (self::isLangTagLetter($ch) || self::isDigit($ch)) {
                $offset += strlen($ch);
                continue;
            }

            if ($ch === '-') {
                $offset += strlen($ch);
                $ch      = $this->stream->peekChar($offset);
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
            $c = $this->stream->peekChar($off);
            if ($c === null) {
                break;
            }

            $name .= $c;
            $off  += strlen($c);
        }

        $value = $this->stream->eatString('@' . $name);
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

    private static function isLangTagLetter(string $ch): bool
    {
        if ($ch === '' || strlen($ch) !== 1) {
            return false;
        }

        $o = ord($ch[0]);

        return ($o >= 0x41 && $o <= 0x5A) || ($o >= 0x61 && $o <= 0x7A);
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
        $ch     = $this->stream->peekChar($offset);
        if ($ch === null) {
            return null;
        }

        if ($ch === '+' || $ch === '-') {
            $offset += strlen($ch);
            $ch      = $this->stream->peekChar($offset);
        }

        if ($ch === null) {
            return null;
        }

        if ($ch === '.') {
            $offset += strlen($ch);
            $ch      = $this->stream->peekChar($offset);
            if ($ch === null || ! self::isDigit($ch)) {
                return null;
            }

            while ($ch !== null && self::isDigit($ch)) {
                $offset += strlen($ch);
                $ch      = $this->stream->peekChar($offset);
            }

            if ($ch === 'e' || $ch === 'E') {
                $offset += strlen($ch);
                $ch      = $this->stream->peekChar($offset);
                if ($ch === '+' || $ch === '-') {
                    $offset += strlen($ch);
                    $ch      = $this->stream->peekChar($offset);
                }

                if ($ch === null || ! self::isDigit($ch)) {
                    return null;
                }

                while ($ch !== null && self::isDigit($ch)) {
                    $offset += strlen($ch);
                    $ch      = $this->stream->peekChar($offset);
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
            $ch      = $this->stream->peekChar($offset);
        }

        if ($ch === null) {
            return [TrigTokenType::Integer, $offset];
        }

        if ($ch === 'e' || $ch === 'E') {
            $offset += strlen($ch);
            $ch      = $this->stream->peekChar($offset);
            if ($ch === '+' || $ch === '-') {
                $offset += strlen($ch);
                $ch      = $this->stream->peekChar($offset);
            }

            if ($ch === null || ! self::isDigit($ch)) {
                return null;
            }

            while ($ch !== null && self::isDigit($ch)) {
                $offset += strlen($ch);
                $ch      = $this->stream->peekChar($offset);
            }

            return [TrigTokenType::Double, $offset];
        }

        if ($ch === '.') {
            $offset          += strlen($ch);
            $ch               = $this->stream->peekChar($offset);
            $hasDigitAfterDot = false;
            while ($ch !== null && self::isDigit($ch)) {
                $hasDigitAfterDot = true;
                $offset          += strlen($ch);
                $ch               = $this->stream->peekChar($offset);
            }

            if ($ch === 'e' || $ch === 'E') {
                $offset += strlen($ch);
                $ch      = $this->stream->peekChar($offset);
                if ($ch === '+' || $ch === '-') {
                    $offset += strlen($ch);
                    $ch      = $this->stream->peekChar($offset);
                }

                if ($ch === null || ! self::isDigit($ch)) {
                    return null;
                }

                while ($ch !== null && self::isDigit($ch)) {
                    $offset += strlen($ch);
                    $ch      = $this->stream->peekChar($offset);
                }

                return [TrigTokenType::Double, $offset];
            }

            return $hasDigitAfterDot ? [TrigTokenType::Decimal, $offset] : null;
        }

        return [TrigTokenType::Integer, $offset];
    }

    private static function isDigit(string $ch): bool
    {
        return $ch !== '' && strlen($ch) === 1 && ord($ch[0]) >= 0x30 && ord($ch[0]) <= 0x39;
    }

    /**
     * Attempts to match a PNAME_LN or PNAME_NS at the current position.
     * Only uses peekChar($offset); does not consume.
     *
     * @return array{TrigTokenType, int}|null [token type, byte length of matched PName] or null if no match
     *
     * [139s] PNAME_NS ::= PN_PREFIX? ':'
     * [140s] PNAME_LN ::= PNAME_NS PN_LOCAL
     * [167s] PN_PREFIX ::= PN_CHARS_BASE ((PN_CHARS | '.')* PN_CHARS)?
     * [168s] PN_LOCAL  ::= (PN_CHARS_U | ':' | [0-9] | PLX) ((PN_CHARS | '.' | ':' | PLX)* (PN_CHARS | ':' | PLX))?
     */
    private function matchPName(): array|null
    {
        $offset = 0;
        $ch     = $this->stream->peekChar($offset);
        if ($ch === null) {
            return null;
        }

        if ($ch === ':') {
            $offset += strlen($ch);
            $type    = $this->isPnLocalStart($offset) ? TrigTokenType::PnameLn : TrigTokenType::PnameNs;
            $len     = $type === TrigTokenType::PnameLn
                ? $offset + $this->pnLocalByteLength($offset)
                : $offset;

            return [$type, $len];
        }

        if (! self::isPnCharsBase($ch)) {
            return null;
        }

        $offset       += strlen($ch);
        $lastWasPnChar = true;

        while (true) {
            $ch = $this->stream->peekChar($offset);
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

                return [$type, $len];
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
            $ch          = $this->stream->peekChar($pos);
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
        $ch = $this->stream->peekChar($offset);
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

    /** Whether there is a PN_LOCAL at $offset (first char of local part). */
    private function isPnLocalStart(int $offset): bool
    {
        $ch = $this->stream->peekChar($offset);
        if ($ch === null) {
            return false;
        }

        if (self::isPnCharsU($ch) || $ch === ':' || self::isDigit($ch)) {
            return true;
        }

        return $this->tryPlxLength($offset) !== 0;
    }

    /** Returns byte length of PLX at $offset, or 0 if not PLX. */
    private function tryPlxLength(int $offset): int
    {
        $ch = $this->stream->peekChar($offset);
        if ($ch === null) {
            return 0;
        }

        if ($ch === '%') {
            $len = strlen($ch);
            $h1  = $this->stream->peekChar($offset + $len);
            if ($h1 === null || ! self::isHex($h1)) {
                return 0;
            }

            $len += strlen($h1);
            $h2   = $this->stream->peekChar($offset + $len);
            if ($h2 === null || ! self::isHex($h2)) {
                return 0;
            }

            return $len + strlen($h2);
        }

        if ($ch === '\\') {
            $len = strlen($ch);
            $esc = $this->stream->peekChar($offset + $len);
            if ($esc !== null && self::isPnLocalEscChar($esc)) {
                return $len + strlen($esc);
            }
        }

        return 0;
    }

    private static function isPnCharsBase(string $ch): bool
    {
        return $ch !== '' && preg_match('/^[\p{L}]$/u', $ch) === 1;
    }

    private static function isPnCharsU(string $ch): bool
    {
        return $ch === '_' || self::isPnCharsBase($ch);
    }

    private static function isPnChars(string $ch): bool
    {
        return $ch !== '' && preg_match('/^[\p{L}_0-9\x{00B7}\x{0300}-\x{036F}\x{203F}\x{2040}-]$/u', $ch) === 1;
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

    private function consumeIriRef(): string
    {
        $source = '';
        $ch     = $this->stream->appendChar($source);
        assert($ch === '<', 'IRIREF must start with <');
        while (true) {
            $ch = $this->stream->appendChar($source);
            if ($ch === null || $ch === '>') {
                break;
            }

            if ($ch !== '\\' || $this->stream->position > strlen($this->stream->buffer)) {
                continue;
            }

            $next = $this->stream->appendChar($source);
            if ($next !== 'u' && $next !== 'U') {
                continue;
            }

            $this->consumeUchar($source);
        }

        return $source;
    }

    /**
     * Consume the hex digits of a \u or \U escape. Caller must have already
     * appended the backslash and 'u' or 'U' to $source (so $source ends with 'u' or 'U').
     */
    private function consumeUchar(string &$source): void
    {
        assert($source !== '' && (substr($source, -1) === 'u' || substr($source, -1) === 'U'), 'source must end with u or U');
        $hexLen = substr($source, -1) === 'u' ? 4 : 8;
        for ($i = 0; $i < $hexLen; $i++) {
            $c = $this->stream->peekChar();
            if ($c === null) {
                break;
            }

            $this->stream->appendChar($source);
        }

        assert(strlen($source) >= 2 + $hexLen, 'incomplete \\u or \\U escape');
    }

    private function consumeString(): string
    {
        $source = '';
        $ch     = $this->stream->peekChar();
        assert($ch !== null, 'expected string delimiter');
        $isLong = false;
        $delim  = $ch;
        if ($delim === '"' || $delim === "'") {
            $this->stream->appendChar($source);
            $next = $this->stream->peekChar();
            if ($next === $delim) {
                $this->stream->appendChar($source);
                $next2 = $this->stream->peekChar();
                if ($next2 === $delim) {
                    $this->stream->appendChar($source);
                    $isLong = true;
                }
            }
        }

        if (! $isLong && strlen($source) === 2) {
            return $source;
        }

        if ($isLong) {
            $end = $delim . $delim . $delim;
            while (true) {
                $ch = $this->stream->peekChar();
                if ($ch === null) {
                    break;
                }

                $this->stream->appendChar($source);
                $pos = strlen($source);
                if ($pos >= 6 && substr($source, -3) === $end && ($pos === 6 || $source[$pos - 4] !== '\\')) {
                    break;
                }
            }

            return $source;
        }

        while (true) {
            $ch = $this->stream->peekChar();
            if ($ch === null) {
                break;
            }

            if ($ch === $delim) {
                $this->stream->appendChar($source);
                break;
            }

            if ($ch === '\\') {
                $this->stream->appendChar($source);
                $next = $this->stream->peekChar();
                if ($next !== null) {
                    $this->stream->appendChar($source);
                    if ($next === 'u' || $next === 'U') {
                        $this->consumeUchar($source);
                    }
                }

                continue;
            }

            $this->stream->appendChar($source);
        }

        return $source;
    }

    private function consumeBlankNodeLabel(): string
    {
        $source = '';
        $this->stream->appendChar($source);
        $this->stream->appendChar($source);
        assert(substr($source, -2) === '_:', 'blank node label must start with _:');
        $rest = substr($this->stream->buffer, $this->stream->position);
        $m    = null;
        if (preg_match('/\G[\p{L}_0-9](?:[\p{L}_0-9.\\-]|\x{00B7}|[\x{0300}-\x{036F}]|[\x{203F}-\x{2040}])*/Su', $rest, $m) === 1) {
            $label = $m[0];
            $len   = strlen($label);
            if ($label[$len - 1] === '.') {
                $len--;
            }

            for ($i = 0; $i < $len; $i++) {
                $this->stream->appendChar($source);
            }
        }

        return $source;
    }
}
