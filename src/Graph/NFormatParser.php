<?php

declare(strict_types=1);

namespace FancySparql\Graph;

use FancySparql\Term\Literal;
use FancySparql\Term\Resource;
use InvalidArgumentException;

use function ctype_xdigit;
use function hexdec;
use function mb_chr;
use function ord;
use function sprintf;
use function strlen;
use function substr;

final class NFormatParser
{
    /** you cannot instantiate this class */
    private function __construct()
    {
    }

  /**
   * Parses a single N-Quads line into a quad or null.
   *
   * @param string $line
   *   One line (subject predicate object [graph] .).
   *
   * @return array{Literal|Resource, Literal|Resource, Literal|Resource}|array{Literal|Resource, Literal|Resource, Literal|Resource, Resource}|null
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
        $object    = self::parseTerm($line, $pos, $len);

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
      // read the opening <
        if ($line[$pos] !== '<') {
            throw new InvalidArgumentException(sprintf('Expected "<" at position %d.', $pos));
        }

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
   */
    private static function parseBlankNodeLabel(string $line, int &$pos, int $len): string
    {
        if ($pos + 2 > $len || $line[$pos] !== '_' || $line[$pos + 1] !== ':') {
            throw new InvalidArgumentException(sprintf('Expected "_:" at position %d.', $pos));
        }

        $pos  += 2;
        $start = $pos;
        while ($pos < $len) {
            $ch = $line[$pos];
            if ($ch === ' ' || $ch === "\t" || $ch === '.') {
                break;
            }

            $pos++;
        }

        return '_:' . substr($line, $start, $pos - $start);
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
            $start = $pos;
            while ($pos < $len && self::isLangTagChar($line[$pos], $pos > $start)) {
                $pos++;
            }

            $lang = substr($line, $start, $pos - $start);
            if ($lang === '') {
                throw new InvalidArgumentException(sprintf('Missing language tag at position %d.', $pos));
            }
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

  /**
   * Checks if a character is allowed in a language tag (LANGTAG).
   */
    private static function isLangTagChar(string $ch, bool $afterFirst): bool
    {
        if ($ch === '-') {
            return $afterFirst;
        }

        if (strlen($ch) !== 1) {
            return false;
        }

        $o = ord($ch);

        return ($o >= 0x61 && $o <= 0x7A) || ($o >= 0x41 && $o <= 0x5A) || ($afterFirst && $o >= 0x30 && $o <= 0x39);
    }
}
