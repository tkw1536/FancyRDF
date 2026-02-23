<?php

declare(strict_types=1);

namespace FancyRDF\Formats\TrigReader;

use function assert;
use function fread;
use function max;
use function mb_substr;
use function ord;
use function preg_match;
use function strcasecmp;
use function strlen;
use function strpos;
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
    private const int READ_CHUNK_SIZE = 8192;

    /** Longest keyword length + 1 for word-boundary lookahead (e.g. "false" = 5). */
    private const int KEYWORD_LOOKAHEAD = 6;

    /** Longest possible UTF-8 code point length. */
    private const int MAX_CODE_POINT_LENGTH = 4;



    private TrigTokenType $currentTokenType = TrigTokenType::EndOfInput;

    private string $currentTokenValue = '';

    /** @param resource $source Stream to read from (e.g. from fopen). */
    public function __construct(
        private readonly mixed $source,
        private readonly int $readChunkSize = self::READ_CHUNK_SIZE,
    ) {
    }

    public function next(): bool
    {
        $this->skipWhitespaceAndComments();
        $ch = $this->peekChar();
        if ($ch === null) {
            $this->currentTokenType  = TrigTokenType::EndOfInput;
            $this->currentTokenValue = '';

            return false;
        }

        $type = $this->recognizeToken($ch);

        if ($type === null) {
            $this->currentTokenType  = TrigTokenType::EndOfInput;
            $this->currentTokenValue = '';

            return false;
        }

        $this->currentTokenType  = $type;
        $this->currentTokenValue = $this->consumeRecognizedToken($type);
        $this->slideBuffer();

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
    // Buffer management
    // ================================

    /**
     * The current buffer of characters read from the underlying source.
     */
    private string $buffer = '';

    /**
     * The current position in the buffer.
     */
    private int $position = 0;

    /**
     * Refill reads data from the underlying stream into the buffer.
     *
     * After a call to refill at least one of the following is true:
     *
     * - there are at least $numBytes read from the stream into the buffer past the current position.
     * - the input stream has ended, and all available bytes are available in the buffer.
     */
    private function refill(int $numBytes = 1): void
    {
        $available = strlen($this->buffer) - $this->position;
        while ($available < $numBytes) {
            $chunk = fread($this->source, max(1, $this->readChunkSize));
            if ($chunk === false || $chunk === '') {
                return;
            }

            $this->buffer .= $chunk;
            $available     = strlen($this->buffer) - $this->position;
        }
    }

    /**
     * Slides the buffer over, removing any characters before the current position from the buffer.
     */
    private function slideBuffer(): void
    {
        $this->buffer   = substr($this->buffer, $this->position);
        $this->position = 0;
    }

    /**
     * Returns the next character in the input, loading buffer if needed.
     *
     * @return non-empty-string|null
     *     null if the end of input has been reached.
     */
    private function peekChar(): string|null
    {
        // Load the substring from the current position.
        $this->refill(self::MAX_CODE_POINT_LENGTH);
        $rest = substr($this->buffer, $this->position);
        if ($rest === '') {
            return null;
        }

        return mb_substr($rest, 0, 1);
    }

    /**
     * Advances the position by the given character.
     */
    private function eatChar(string $char): void
    {
        $this->position += strlen($char);
    }

    /**
     * Peeks at the next character, appends it to dest, and returns it.
     */
    private function appendChar(string &$dest): string|null
    {
        $ch = $this->peekChar();
        if ($ch === null) {
            return null;
        }

        $dest .= $ch;
        $this->eatChar($ch);

        return $ch;
    }

    /**
     * Reads a string of the same length as s from the buffer.
     */
    private function eatString(string $s): string
    {
        $this->refill(strlen($s));
        $result          = substr($this->buffer, $this->position, strlen($s));
        $this->position += strlen($s);

        return $result;
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
        $ch = $this->peekChar();
        while (true) {
            // skip pure whitespace
            while ($ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r") {
                $this->eatChar($ch);
                $ch = $this->peekChar();
            }

            // if there is a comment, skip until end of line.
            // if there isn't a comment, we have reached end-of-input.
            if ($ch !== '#') {
                return;
            }

            while ($ch !== "\n" && $ch !== "\r" && $ch !== null) {
                $this->eatChar($ch);
                $ch = $this->peekChar();
            }
        }
    }

    /**
     * Determine token type from the first character (longest match).
     */
    private function recognizeToken(string $ch): TrigTokenType|null
    {
        $this->refill(self::KEYWORD_LOOKAHEAD);
        $rest = substr($this->buffer, $this->position);

        if ($ch === '@') {
            if (self::startsWith($rest, '@prefix')) {
                return TrigTokenType::AtPrefix;
            }

            if (self::startsWith($rest, '@base')) {
                return TrigTokenType::AtBase;
            }

            if (preg_match('/^@[a-zA-Z]+(-[a-zA-Z0-9]+)*/u', $rest) === 1) {
                return TrigTokenType::LangTag;
            }
        }

        if (self::matchCaseInsensitiveKeyword($rest, 'GRAPH')) {
            return TrigTokenType::Graph;
        }

        if (self::matchCaseInsensitiveKeyword($rest, 'PREFIX')) {
            return TrigTokenType::Prefix;
        }

        if (self::matchCaseInsensitiveKeyword($rest, 'BASE')) {
            return TrigTokenType::Base;
        }

        if ($ch === 'a') {
            if (self::isWordBoundaryAfter($rest, 'a')) {
                return TrigTokenType::A;
            }
        }

        if (self::startsWith($rest, 'true') && self::isWordBoundaryAfter($rest, 'true')) {
            return TrigTokenType::True;
        }

        if (self::startsWith($rest, 'false') && self::isWordBoundaryAfter($rest, 'false')) {
            return TrigTokenType::False;
        }

        if ($ch === '<') {
            return TrigTokenType::IriRef;
        }

        if (self::startsWith($rest, "'''")) {
            return TrigTokenType::String;
        }

        if (self::startsWith($rest, '"""')) {
            return TrigTokenType::String;
        }

        if ($ch === '"' || $ch === "'") {
            return TrigTokenType::String;
        }

        if ($ch === '_' && strlen($rest) >= 2 && $rest[1] === ':') {
            return TrigTokenType::BlankNodeLabel;
        }

        if ($ch === '[') {
            return $this->isAnonAhead() ? TrigTokenType::Anon : TrigTokenType::LSquare;
        }

        if ($ch === '.') {
            return TrigTokenType::Dot;
        }

        if ($ch === ';') {
            return TrigTokenType::Semicolon;
        }

        if ($ch === ',') {
            return TrigTokenType::Comma;
        }

        if ($ch === ']') {
            return TrigTokenType::RSquare;
        }

        if ($ch === '(') {
            return TrigTokenType::LParen;
        }

        if ($ch === ')') {
            return TrigTokenType::RParen;
        }

        if ($ch === '{') {
            return TrigTokenType::LCurly;
        }

        if ($ch === '}') {
            return TrigTokenType::RCurly;
        }

        if (strlen($rest) >= 2 && $rest[0] === '^' && $rest[1] === '^') {
            return TrigTokenType::HatHat;
        }

        if (self::matchDouble($rest)) {
            return TrigTokenType::Double;
        }

        if (self::matchDecimal($rest)) {
            return TrigTokenType::Decimal;
        }

        if (self::matchInteger($rest)) {
            return TrigTokenType::Integer;
        }

        if (self::matchPnameLn($rest)) {
            return TrigTokenType::PnameLn;
        }

        if (self::matchPnameNs($rest)) {
            return TrigTokenType::PnameNs;
        }

        return null;
    }

    private function consumeRecognizedToken(TrigTokenType $type): string
    {
        $source = '';

        switch ($type) {
            case TrigTokenType::AtPrefix:
                return $this->eatString('@prefix');

            case TrigTokenType::AtBase:
                return $this->eatString('@base');

            case TrigTokenType::A:
                return $this->eatString('a');

            case TrigTokenType::True:
                return $this->eatString('true');

            case TrigTokenType::False:
                return $this->eatString('false');

            case TrigTokenType::Graph:
                return $this->eatString('GRAPH');

            case TrigTokenType::Prefix:
                return $this->eatString('PREFIX');

            case TrigTokenType::Base:
                return $this->eatString('BASE');

            case TrigTokenType::Dot:
            case TrigTokenType::Semicolon:
            case TrigTokenType::Comma:
            case TrigTokenType::LSquare:
            case TrigTokenType::RSquare:
            case TrigTokenType::LParen:
            case TrigTokenType::RParen:
            case TrigTokenType::LCurly:
            case TrigTokenType::RCurly:
                $this->appendChar($source);

                return $source;

            case TrigTokenType::HatHat:
                return $this->eatString('^^');

            case TrigTokenType::IriRef:
                return $this->consumeIriRef();

            case TrigTokenType::String:
                return $this->consumeString();

            case TrigTokenType::BlankNodeLabel:
                return $this->consumeBlankNodeLabel();

            case TrigTokenType::Anon:
                return $this->consumeAnon();

            case TrigTokenType::Integer:
            case TrigTokenType::Decimal:
            case TrigTokenType::Double:
                return $this->consumeNumber();

            case TrigTokenType::LangTag:
                return $this->consumeLangTag();

            case TrigTokenType::PnameNs:
                return $this->consumePnameNs();

            case TrigTokenType::PnameLn:
                return $this->consumePnameLn();

            default:
                return '';
        }
    }

    private function consumeIriRef(): string
    {
        $source = '';
        $ch     = $this->appendChar($source);
        assert($ch === '<', 'IRIREF must start with <');
        while (true) {
            $ch = $this->appendChar($source);
            if ($ch === null || $ch === '>') {
                break;
            }

            if ($ch !== '\\' || $this->position > strlen($this->buffer)) {
                continue;
            }

            $next = $this->appendChar($source);
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
            $c = $this->peekChar();
            if ($c === null) {
                break;
            }

            $this->appendChar($source);
        }

        assert(strlen($source) >= 2 + $hexLen, 'incomplete \\u or \\U escape');
    }

    private function consumeString(): string
    {
        $source = '';
        $ch     = $this->peekChar();
        assert($ch !== null, 'expected string delimiter');
        $isLong = false;
        $delim  = $ch;
        if ($delim === '"' || $delim === "'") {
            $this->appendChar($source);
            $next = $this->peekChar();
            if ($next === $delim) {
                $this->appendChar($source);
                $next2 = $this->peekChar();
                if ($next2 === $delim) {
                    $this->appendChar($source);
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
                $ch = $this->peekChar();
                if ($ch === null) {
                    break;
                }

                $this->appendChar($source);
                $pos = strlen($source);
                if ($pos >= 6 && substr($source, -3) === $end && ($pos === 6 || $source[$pos - 4] !== '\\')) {
                    break;
                }
            }

            return $source;
        }

        while (true) {
            $ch = $this->peekChar();
            if ($ch === null) {
                break;
            }

            if ($ch === $delim) {
                $this->appendChar($source);
                break;
            }

            if ($ch === '\\') {
                $this->appendChar($source);
                $next = $this->peekChar();
                if ($next !== null) {
                    $this->appendChar($source);
                    if ($next === 'u' || $next === 'U') {
                        $this->consumeUchar($source);
                    }
                }

                continue;
            }

            $this->appendChar($source);
        }

        return $source;
    }

    private function consumeBlankNodeLabel(): string
    {
        $source = '';
        $this->appendChar($source);
        $this->appendChar($source);
        assert(substr($source, -2) === '_:', 'blank node label must start with _:');
        $rest = substr($this->buffer, $this->position);
        $m    = null;
        if (preg_match('/\G[\p{L}_0-9](?:[\p{L}_0-9.\\-]|\x{00B7}|[\x{0300}-\x{036F}]|[\x{203F}-\x{2040}])*/Su', $rest, $m) === 1) {
            $label = $m[0];
            $len   = strlen($label);
            if ($label[$len - 1] === '.') {
                $len--;
            }

            for ($i = 0; $i < $len; $i++) {
                $this->appendChar($source);
            }
        }

        return $source;
    }

    private function consumeAnon(): string
    {
        $source = '';
        $this->appendChar($source);
        assert($source === '[', 'ANON must start with [');
        while (true) {
            $ch = $this->peekChar();
            if ($ch === null || $ch === ']') {
                break;
            }

            if ($ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r") {
                $this->appendChar($source);
                continue;
            }

            if ($ch === '#') {
                while (true) {
                    $this->appendChar($source);
                    $ch = $this->peekChar();
                    if ($ch === null || $ch === "\n" || $ch === "\r") {
                        break;
                    }
                }

                continue;
            }

            break;
        }

        $ch = $this->peekChar();
        assert($ch === ']', 'ANON must be [ WS* ]');
        $this->appendChar($source);

        return $source;
    }

    private function consumeNumber(): string
    {
        $source = '';
        $ch     = $this->peekChar();
        if ($ch === '+' || $ch === '-') {
            $this->appendChar($source);
        }

        while (true) {
            $ch = $this->peekChar();
            if ($ch === null || ! self::isDigit($ch)) {
                break;
            }

            $this->appendChar($source);
        }

        if ($this->peekChar() === '.') {
            $this->appendChar($source);
            while (true) {
                $ch = $this->peekChar();
                if ($ch === null || ! self::isDigit($ch)) {
                    break;
                }

                $this->appendChar($source);
            }
        }

        $ch = $this->peekChar();
        if ($ch === 'e' || $ch === 'E') {
            $this->appendChar($source);
            $ch = $this->peekChar();
            if ($ch === '+' || $ch === '-') {
                $this->appendChar($source);
            }

            while (true) {
                $ch = $this->peekChar();
                if ($ch === null || ! self::isDigit($ch)) {
                    break;
                }

                $this->appendChar($source);
            }
        }

        return $source;
    }

    private function consumeLangTag(): string
    {
        $source = '';
        $rest   = substr($this->buffer, $this->position);
        $m      = null;
        if (preg_match('/\G@[a-zA-Z]+(-[a-zA-Z0-9]+)*/u', $rest, $m) === 1) {
            $len = strlen($m[0]);
            for ($i = 0; $i < $len; $i++) {
                $this->appendChar($source);
            }
        } else {
            $this->appendChar($source);
        }

        return $source;
    }

    private function consumePnameNs(): string
    {
        $source = '';
        $rest   = substr($this->buffer, $this->position);
        $m      = null;
        if (preg_match('/\G[a-zA-Z\x{00C0}-\x{00D6}\x{00D8}-\x{00F6}\x{00F8}-\x{02FF}](?:[\p{L}_0-9.\x{00B7}\x{0300}-\x{036F}\x{203F}\x{2040}-]|\\.)*/u', $rest, $m) === 1) {
            foreach (self::mbChars($m[0]) as $_c) {
                $this->appendChar($source);
            }
        }

        $ch = $this->peekChar();
        if ($ch === ':') {
            $this->appendChar($source);
        }

        return $source;
    }

    private function consumePnameLn(): string
    {
        $source = $this->consumePnameNs();
        $rest   = substr($this->buffer, $this->position);
        $m      = null;
        if (preg_match('/\G(?:[\p{L}_]|[0-9]|%[0-9A-Fa-f]{2}|\\[._~!\$&\'()*+,;=\/?#@%-])(?:[\p{L}_0-9.\x{00B7}\x{0300}-\x{036F}\x{203F}\x{2040}-]|[:%]|\\[._~!\$&\'()*+,;=\/?#@%-])*/u', $rest, $m) === 1) {
            $local = $m[0];
            $len   = strlen($local);
            $i     = 0;
            while ($i < $len) {
                $b = ord($local[$i]);
                $n = $b < 0x80 ? 1 : ($b < 0xE0 ? 2 : ($b < 0xF0 ? 3 : 4));
                for ($j = 0; $j < $n && $i < $len; $j++, $i++) {
                    $this->appendChar($source);
                }
            }
        }

        return $source;
    }

    /** @return list<string> */
    private static function mbChars(string $s): array
    {
        $chars = [];
        $len   = strlen($s);
        $i     = 0;
        while ($i < $len) {
            $b       = ord($s[$i]);
            $n       = $b < 0x80 ? 1 : ($b < 0xE0 ? 2 : ($b < 0xF0 ? 3 : 4));
            $chars[] = substr($s, $i, $n);
            $i      += $n;
        }

        return $chars;
    }

    private static function startsWith(string $buffer, string $prefix): bool
    {
        $len = strlen($prefix);
        if (strlen($buffer) < $len) {
            return false;
        }

        return substr($buffer, 0, $len) === $prefix;
    }

    private static function isWordBoundaryAfter(string $buffer, string $word): bool
    {
        $len = strlen($word);
        if (strlen($buffer) < $len) {
            return false;
        }

        if (substr($buffer, 0, $len) !== $word) {
            return false;
        }

        if (strlen($buffer) === $len) {
            return true;
        }

        $next = $buffer[$len];

        return $next === ' ' || $next === "\t" || $next === "\n" || $next === "\r" || $next === '.' || $next === ';' || $next === ',' || $next === '[' || $next === ']' || $next === '(' || $next === ')' || $next === '{' || $next === '}' || $next === '<' || $next === '>' || $next === '"' || $next === "'" || $next === '#' || $next === '^' || $next === '@';
    }

    private static function matchCaseInsensitiveKeyword(string $buffer, string $keyword): bool
    {
        $len = strlen($keyword);
        if (strlen($buffer) < $len) {
            return false;
        }

        return strcasecmp(substr($buffer, 0, $len), $keyword) === 0 && (strlen($buffer) === $len || ! self::isPnameChar(substr($buffer, $len, 1)));
    }

    /**
     * Whether after '[' the next non-WS, non-comment character is ']' (i.e. this is ANON).
     */
    private function isAnonAhead(): bool
    {
        // TODO: Bug for edge-of-page
        $rest = substr($this->buffer, $this->position + 1);
        $len  = strlen($rest);
        $p    = 0;
        while ($p < $len) {
            $c = $rest[$p];
            if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") {
                $p++;
                continue;
            }

            if ($c === '#') {
                while ($p < $len && $rest[$p] !== "\n" && $rest[$p] !== "\r") {
                    $p++;
                }

                continue;
            }

            break;
        }

        return $p < $len && $rest[$p] === ']';
    }

    private static function isPnameChar(string $ch): bool
    {
        if ($ch === '') {
            return false;
        }

        $o = ord($ch[0]);

        return ($o >= 0x41 && $o <= 0x5A) || ($o >= 0x61 && $o <= 0x7A) || ($o >= 0x30 && $o <= 0x39) || $o === 0x5F || $o === 0x2D || $o === 0x2E;
    }

    private static function matchInteger(string $buffer): bool
    {
        return preg_match('/^[+-]?[0-9]+(?![\p{L}_0-9.])/u', $buffer) === 1;
    }

    private static function matchDecimal(string $buffer): bool
    {
        return preg_match('/^[+-]?(?:[0-9]*\\.[0-9]+)(?![0-9Ee])/u', $buffer) === 1;
    }

    private static function matchDouble(string $buffer): bool
    {
        return preg_match('/^[+-]?(?:[0-9]+\\.[0-9]*(?:[eE][+-]?[0-9]+)|\\.[0-9]+(?:[eE][+-]?[0-9]+)|[0-9]+[eE][+-]?[0-9]+)/u', $buffer) === 1;
    }

    private static function matchPnameNs(string $buffer): bool
    {
        return preg_match('/^[a-zA-Z\x{00C0}-\x{00D6}\x{00D8}-\x{00F6}\x{00F8}-\x{02FF}](?:[\p{L}_0-9.\x{00B7}\x{0300}-\x{036F}\x{203F}\x{2040}-]|\\.)*:/u', $buffer) === 1 || (strlen($buffer) >= 1 && $buffer[0] === ':');
    }

    private static function matchPnameLn(string $buffer): bool
    {
        if (preg_match('/^[a-zA-Z\x{00C0}-\x{00D6}\x{00D8}-\x{00F6}\x{00F8}-\x{02FF}](?:[\p{L}_0-9.\x{00B7}\x{0300}-\x{036F}\x{203F}\x{2040}-]|\\.)*:/u', $buffer) !== 1 && ! (strlen($buffer) >= 1 && $buffer[0] === ':')) {
            return false;
        }

        $colonPos = strpos($buffer, ':');
        if ($colonPos === false) {
            return false;
        }

        $local = substr($buffer, $colonPos + 1);

        return $local !== '' && preg_match('/^(?:[\p{L}_]|[0-9]|%[0-9A-Fa-f]{2}|\\[._~!\$&\'()*+,;=\/?#@%-])(?:[\p{L}_0-9.\x{00B7}\x{0300}-\x{036F}\x{203F}\x{2040}-]|[:%]|\\[._~!\$&\'()*+,;=\/?#@%-])*/u', $local) === 1;
    }

    private static function isDigit(string $ch): bool
    {
        if ($ch === '' || strlen($ch) > 1) {
            return false;
        }

        $o = ord($ch[0]);

        return $o >= 0x30 && $o <= 0x39;
    }
}
