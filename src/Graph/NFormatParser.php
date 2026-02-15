<?php

declare(strict_types=1);

namespace FancySparql\Graph;

use FancySparql\Term\Literal;
use FancySparql\Term\Resource;
use InvalidArgumentException;
use Traversable;

use function ctype_xdigit;
use function hexdec;
use function mb_chr;
use function mb_ord;
use function mb_substr;
use function ord;
use function preg_match;
use function preg_split;
use function sprintf;
use function strlen;
use function substr;

use const PREG_SPLIT_NO_EMPTY;

final class NFormatParser
{
    private const int COLON                = 0x3A;   // ':'
    private const int FULL_STOP            = 0x2E;   // '.'
    private const int HYPHEN_MINUS         = 0x2D;   // '-'
    private const int LOW_LINE             = 0x5F;   // '_'
    private const int DIGIT_ZERO           = 0x30;   // '0'
    private const int DIGIT_NINE           = 0x39;   // '9'
    private const int MIDDLE_DOT           = 0x00B7; // '·'
    private const int COMBINING_FIRST      = 0x0300;
    private const int COMBINING_LAST       = 0x036F;
    private const int PERMITTED_203F_FIRST = 0x203F;
    private const int PERMITTED_2040_LAST  = 0x2040;

    // PN_CHARS_BASE ranges [A-Z] [a-z] and Unicode letter ranges
    private const int LATIN_CAPITAL_A  = 0x41;
    private const int LATIN_CAPITAL_Z  = 0x5A;
    private const int LATIN_SMALL_A    = 0x61;
    private const int LATIN_SMALL_Z    = 0x7A;
    private const int PN_00C0_FIRST    = 0x00C0;
    private const int PN_00D6_LAST     = 0x00D6;
    private const int PN_00D8_FIRST    = 0x00D8;
    private const int PN_00F6_LAST     = 0x00F6;
    private const int PN_00F8_FIRST    = 0x00F8;
    private const int PN_02FF_LAST     = 0x02FF;
    private const int PN_0370_FIRST    = 0x0370;
    private const int PN_037D_LAST     = 0x037D;
    private const int PN_037F_FIRST    = 0x037F;
    private const int PN_1FFF_LAST     = 0x1FFF;
    private const int PN_200C_FIRST    = 0x200C;
    private const int PN_200D_LAST     = 0x200D;
    private const int PN_2070_FIRST    = 0x2070;
    private const int PN_218F_LAST     = 0x218F;
    private const int PN_2C00_FIRST    = 0x2C00;
    private const int PN_2FEF_LAST     = 0x2FEF;
    private const int PN_3001_FIRST    = 0x3001;
    private const int PN_D7FF_LAST     = 0xD7FF;
    private const int PN_F900_FIRST    = 0xF900;
    private const int PN_FDCF_LAST     = 0xFDCF;
    private const int PN_FDF0_FIRST    = 0xFDF0;
    private const int PN_FFFD_LAST     = 0xFFFD;
    private const int PN_10000_FIRST   = 0x10000;
    private const int PN_EFFFF_LAST    = 0xEFFFF;

    /** you cannot instantiate this class */
    private function __construct()
    {
    }

    /**
     * Reads content from the given string.
     *
     * @return Traversable<array{Literal|Resource, Resource, Literal|Resource}|array{Literal|Resource, Resource, Literal|Resource, Resource}>
     *
     * @throws InvalidArgumentException
     */
    public static function parse(string $source): Traversable
    {
        $lines = preg_split('/\r\n|\r|\n/', $source, -1, PREG_SPLIT_NO_EMPTY);
        if ($lines === false) {
            throw new InvalidArgumentException('Failed to split lines from source.');
        }

        foreach ($lines as $line) {
            $term = self::parseLine($line);
            if ($term === null) {
                continue;
            }

            yield $term;
        }
    }

  /**
   * Parses a single N-Quads line into a quad or null.
   *
   * @param string $line
   *   One line (subject predicate object [graph] .).
   *
   * @return array{Literal|Resource, Resource, Literal|Resource}|array{Literal|Resource, Resource, Literal|Resource, Resource}|null
   *   The triple, quad, or null if the line is empty or a comment.
   *
   * @throws InvalidArgumentException
   *   When the line is not valid N-Quads.
   */
    public static function parseLine(string $line): array|null
    {
        $pos = 0;

        $len = strlen($line);
        self::skipWhitespaceAndComments($line, $pos, $len);
        if ($pos >= $len) {
            return null;
        }

        // parse subject, predicate, object -- these are required.
        $subject   = self::parseTerm($line, $pos, $len);
        $predicate = self::parseTerm($line, $pos, $len);
        if (! $predicate instanceof Resource) {
            throw new InvalidArgumentException(sprintf('Predicate must be IRI at position %d.', $pos));
        }

        $object = self::parseTerm($line, $pos, $len);

        // optionally parse a graph
        $graph = null;
        self::skipWhitespace($line, $pos, $len);
        if ($pos < $len && $line[$pos] !== '.') {
            $graph = self::parseTerm($line, $pos, $len);
            if (! $graph instanceof Resource) {
                throw new InvalidArgumentException(sprintf('Graph label must be IRI or blank node at position %d.', $pos));
            }

            self::skipWhitespace($line, $pos, $len);
        }

        // require the trailing dot..
        if ($pos >= $len || $line[$pos] !== '.') {
            throw new InvalidArgumentException(sprintf('Expected "." at end of statement around position %d.', $pos));
        }

        return $graph === null ? [$subject, $predicate, $object] : [$subject, $predicate, $object, $graph];
    }

  /**
   * Parses a single RDF term (IRI, blank node, or literal).
   *
   * @throws InvalidArgumentException
   */
    private static function parseTerm(string $line, int &$pos, int $len): Literal|Resource
    {
        // skip any leading whitespace.
        self::skipWhitespace($line, $pos, $len);
        if ($pos >= $len) {
            throw new InvalidArgumentException('Unexpected end of line while reading term.');
        }

        // look at the current character and decide what to parse.
        $ch = $line[$pos];
        if ($ch === '<') {
            return new Resource(self::parseIriRef($line, $pos, $len));
        }

        if ($ch === '_' && $pos + 1 < $len && $line[$pos + 1] === ':') {
            return new Resource(self::parseBlankNodeLabel($line, $pos, $len));
        }

        if ($ch === '"') {
            return self::parseLiteral($line, $pos, $len);
        }

        throw new InvalidArgumentException(sprintf('Invalid term start at position %d (char %s).', $pos, $ch));
    }

  /**
   * Skips whitespace and comment (from # to end of line).
   */
    private static function skipWhitespaceAndComments(string $line, int &$pos, int $len): void
    {
        self::skipWhitespace($line, $pos, $len);
        if ($pos >= $len || $line[$pos] !== '#') {
            return;
        }

        $pos = $len;
    }

  /**
   * Skips space and tab.
   */
    private static function skipWhitespace(string $line, int &$pos, int $len): void
    {
        while ($pos < $len && ($line[$pos] === ' ' || $line[$pos] === "\t")) {
            $pos++;
        }
    }

  /**
   * Parses an IRI reference <...>, with \u and \U unescaping.
   *
   * @return string
   *   The IRI string.
   */
    private static function parseIriRef(string $line, int &$pos, int $len): string
    {
        $pos++;

        $start = $pos;
        $buf   = '';
        while ($pos < $len) {
            $ch = $line[$pos];

            // closing '>' found, end of the IRI reference.
            if ($ch === '>') {
                $end = $pos;
                $pos++;

                return $buf . substr($line, $start, $end - $start);
            }

            // parse an escape sequence.
            if ($ch === '\\' && $pos + 1 < $len) {
                $buf  .= substr($line, $start, $pos - $start);
                $buf  .= self::decodeUchar($line, $pos, $len);
                $start = $pos;
                continue;
            }

            // move to the next character.
            $pos++;
        }

        throw new InvalidArgumentException('Unclosed IRI reference.');
    }

  /**
   * Parses a blank node label _:label.
   *
   * Label is built on PN_CHARS_BASE, with: _ and [0-9] anywhere; . anywhere except
   * first or last; -, U+00B7, U+0300–U+036F, U+203F–U+2040 anywhere except first.
   * Colon is not allowed (W3C N-Triples).
   */
    private static function parseBlankNodeLabel(string $line, int &$pos, int $len): string
    {
        $labelStart = $pos;
        $pos       += 2; // skip the _: prefix

        $first = self::readNextCodepoint($line, $pos, $len);
        if ($first === null) {
            throw new InvalidArgumentException(sprintf('Invalid blank node label at position %d.', $pos));
        }

        if (
            $first === self::COLON
            || $first === self::FULL_STOP
            || $first === self::HYPHEN_MINUS
            || $first === self::MIDDLE_DOT
            || ($first >= self::COMBINING_FIRST && $first <= self::COMBINING_LAST)
            || ($first >= self::PERMITTED_203F_FIRST && $first <= self::PERMITTED_2040_LAST)
        ) {
            throw new InvalidArgumentException(sprintf('Invalid blank node label at position %d.', $pos));
        }

        if (! self::isBlankNodeLabelFirstChar($first)) {
            throw new InvalidArgumentException(sprintf('Invalid blank node label at position %d.', $pos));
        }

        $lastWasDot  = false;
        $lastByteLen = 0;
        while ($pos < $len) {
            $prevPos = $pos;
            $ord     = self::readNextCodepoint($line, $pos, $len);
            if ($ord === null) {
                break;
            }

            if ($ord === self::COLON) {
                throw new InvalidArgumentException(sprintf('Colon not allowed in blank node label at position %d.', $pos));
            }

            if ($ord === self::FULL_STOP) {
                $lastWasDot  = true;
                $lastByteLen = $pos - $prevPos;
                continue;
            }

            if (! self::isBlankNodeLabelRestChar($ord)) {
                $pos = $prevPos;
                break;
            }

            $lastWasDot  = false;
            $lastByteLen = $pos - $prevPos;
        }

        if ($lastWasDot) {
            $pos -= $lastByteLen;
        }

        return substr($line, $labelStart, $pos - $labelStart);
    }

  /**
   * PN_CHARS_BASE: first character of a blank node label (letters only, or _ or digit).
   */
    private static function isBlankNodeLabelFirstChar(int $ord): bool
    {
        return self::isPnCharsBase($ord)
            || $ord === self::LOW_LINE
            || ($ord >= self::DIGIT_ZERO && $ord <= self::DIGIT_NINE);
    }

  /**
   * Characters allowed in a blank node label after the first (PN_CHARS_BASE, _, digit, ., -, ·, combining, 203F–2040).
   */
    private static function isBlankNodeLabelRestChar(int $ord): bool
    {
        return self::isPnCharsBase($ord)
            || $ord === self::LOW_LINE
            || ($ord >= self::DIGIT_ZERO && $ord <= self::DIGIT_NINE)
            || $ord === self::FULL_STOP
            || $ord === self::HYPHEN_MINUS
            || $ord === self::MIDDLE_DOT
            || ($ord >= self::COMBINING_FIRST && $ord <= self::COMBINING_LAST)
            || ($ord >= self::PERMITTED_203F_FIRST && $ord <= self::PERMITTED_2040_LAST);
    }

  /**
   * PN_CHARS_BASE per grammar (letter ranges).
   */
    private static function isPnCharsBase(int $ord): bool
    {
        return ($ord >= self::LATIN_CAPITAL_A && $ord <= self::LATIN_CAPITAL_Z)
            || ($ord >= self::LATIN_SMALL_A && $ord <= self::LATIN_SMALL_Z)
            || ($ord >= self::PN_00C0_FIRST && $ord <= self::PN_00D6_LAST)
            || ($ord >= self::PN_00D8_FIRST && $ord <= self::PN_00F6_LAST)
            || ($ord >= self::PN_00F8_FIRST && $ord <= self::PN_02FF_LAST)
            || ($ord >= self::PN_0370_FIRST && $ord <= self::PN_037D_LAST)
            || ($ord >= self::PN_037F_FIRST && $ord <= self::PN_1FFF_LAST)
            || ($ord >= self::PN_200C_FIRST && $ord <= self::PN_200D_LAST)
            || ($ord >= self::PN_2070_FIRST && $ord <= self::PN_218F_LAST)
            || ($ord >= self::PN_2C00_FIRST && $ord <= self::PN_2FEF_LAST)
            || ($ord >= self::PN_3001_FIRST && $ord <= self::PN_D7FF_LAST)
            || ($ord >= self::PN_F900_FIRST && $ord <= self::PN_FDCF_LAST)
            || ($ord >= self::PN_FDF0_FIRST && $ord <= self::PN_FFFD_LAST)
            || ($ord >= self::PN_10000_FIRST && $ord <= self::PN_EFFFF_LAST);
    }

  /**
   * Reads the next UTF-8 code point at $pos and advances $pos.
   */
    private static function readNextCodepoint(string $line, int &$pos, int $len): int|null
    {
        if ($pos >= $len) {
            return null;
        }

        // Extract the next character from the line.
        // 4 is the maximum number of bytes for a UTF-8 character.
        $chunk = substr($line, $pos, 4);
        $char  = mb_substr($chunk, 0, 1);
        $pos  += strlen($char);

        return mb_ord($char, 'UTF-8');
    }
  /**
   * Parses a literal: "..." with optional @lang or ^^<datatype>.
   *
   * @throws InvalidArgumentException
   */
    private static function parseLiteral(string $line, int &$pos, int $len): Literal
    {
        $lexical = self::parseStringLiteralQuote($line, $pos, $len);
        self::skipWhitespace($line, $pos, $len);

        $lang     = null;
        $datatype = null;

        if ($pos < $len && $line[$pos] === '@') {
            $pos++;
            $rest = substr($line, $pos, $len - $pos);
            if ($rest === '' || preg_match('/^[a-zA-Z]+(-[a-zA-Z0-9]+)*/', $rest, $m) !== 1 || $m[0] === '') {
                throw new InvalidArgumentException(sprintf('Missing language tag at position %d.', $pos));
            }
            $lang = $m[0];
            $pos += strlen($lang);
        } elseif ($pos + 1 < $len && $line[$pos] === '^' && $line[$pos + 1] === '^') {
            $pos     += 2;
            $datatype = self::parseIriRef($line, $pos, $len);
            if ($datatype === '') {
                throw new InvalidArgumentException(sprintf('Missing datatype at position %d.', $pos));
            }
        }

        return new Literal($lexical, $lang, $datatype);
    }

  /**
   * Parses a quoted string "...", with ECHAR and UCHAR unescaping.
   *
   * @return string
   *   The lexical form.
   */
    private static function parseStringLiteralQuote(string $line, int &$pos, int $len): string
    {
      // Read the starting quote.
        if ($line[$pos] !== '"') {
            throw new InvalidArgumentException(sprintf('Expected \'"\' at position %d.', $pos));
        }

        $pos++;

        // Read the string contents.
        $buf   = '';
        $start = $pos;
        while ($pos < $len) {
            $ch = $line[$pos];

            // Closing quote found, end of string.
            if ($ch === '"') {
                $pos++;

                return $buf . substr($line, $start, $pos - 1 - $start);
            }

            // Parse an escape sequence.
            if ($ch === '\\' && $pos + 1 < $len) {
                $buf .= substr($line, $start, $pos - $start);
                $next = $line[$pos + 1];
                if ($next === 'u' || $next === 'U') {
                    $buf .= self::decodeUchar($line, $pos, $len);
                } else {
                    $buf .= self::decodeEchar($next);
                    $pos += 2;
                }

                $start = $pos;
                continue;
            }

            // and move to the next character.
            $pos++;
        }

        throw new InvalidArgumentException('Unclosed string literal.');
    }

  /**
   * Decodes one \uXXXX or \UXXXXXXXX sequence; $pos is advanced.
   */
    private static function decodeUchar(string $line, int &$pos, int $len): string
    {
        // consume the backslash character.
        if ($line[$pos] !== '\\') {
            throw new InvalidArgumentException(sprintf('Expected backslash at position %d.', $pos));
        }

        $pos++;
        if ($pos >= $len) {
            throw new InvalidArgumentException('Unexpected end in escape sequence.');
        }

        // Check if we have a small u or a large U.
        if ($line[$pos] !== 'u' && $line[$pos] !== 'U') {
            throw new InvalidArgumentException(sprintf('Expected \\u or \\U at position %d.', $pos));
        }

        $u = $line[$pos] === 'u';
        $pos++;

        // determine the number of characters to read.
        $hexLen = $u ? 4 : 8;
        if ($pos + $hexLen > $len) {
            throw new InvalidArgumentException(sprintf('Incomplete \\%s escape at position %d.', $u ? 'u' : 'U', $pos));
        }

        // read the escape sequence.
        $hex = substr($line, $pos, $hexLen);
        if (! ctype_xdigit($hex)) {
            throw new InvalidArgumentException(sprintf('Invalid hex in \\%s escape at position %d.', $u ? 'u' : 'U', $pos));
        }

        $pos += $hexLen;

        // do the actual decoding.
        $ord = (int) hexdec($hex);
        if ($ord > 0x10FFFF) {
            throw new InvalidArgumentException(sprintf('Code point U+%X out of range.', $ord));
        }

        return mb_chr($ord, 'UTF-8');
    }

  /**
   * Decodes one ECHAR (single character escape).
   */
    private static function decodeEchar(string $char): string
    {
        return match ($char) {
            't' => "\t",
            'b' => "\x08",
            'n' => "\n",
            'r' => "\r",
            'f' => "\f",
            '"' => '"',
            '\'' => "'",
            '\\' => '\\',
            default => throw new InvalidArgumentException(sprintf('Invalid escape sequence \\%s.', $char)),
        };
    }
}
