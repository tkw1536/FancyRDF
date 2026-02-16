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
use function preg_match;
use function preg_split;
use function sprintf;
use function str_ends_with;
use function strlen;
use function substr;

use const PREG_SPLIT_NO_EMPTY;

/**
 * Parses n-triples or n-quads from a file and streams them to the caller.
 *
 * The implementation guarantees that it can parse any valid Sparql 1.1 n-triples and n-quads file. This is
 * guaranteed by being able to parse all valid ntriples and nquads from the RDF 1.1 test suite.
 *
 * This guarantee DOES NOT apply negatively: Invalid terms may serialize into an invalid Term instances,
 * may parse into a completely unrelated terms, or may throw an exception.
 *
 * @see https://www.w3.org/TR/n-triples/
 * @see https://www.w3.org/TR/n-quads/
 * @see https://www.w3.org/TR/rdf11-testcases/
 */
final class NFormatParser
{
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

        self::skipWhitespace($line, $pos, $len);
        if ($pos >= $len || $line[$pos] === '#') {
            return null;
        }

        $subject = self::parseTerm($line, $pos, $len);

        self::skipWhitespace($line, $pos, $len);
        if ($pos >= $len) {
            throw new InvalidArgumentException('Unexpected end of line while reading term.');
        }

        $predicate = self::parseTerm($line, $pos, $len);

        if (! $predicate instanceof Resource) {
            throw new InvalidArgumentException(sprintf('Predicate must be IRI at position %d.', $pos));
        }

        self::skipWhitespace($line, $pos, $len);
        if ($pos >= $len) {
            throw new InvalidArgumentException('Unexpected end of line while reading term.');
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

        $rest = substr($line, $pos, $len - $pos);
        if (
            $rest === '' || preg_match(
                '/^[\p{L}_0-9](?:[\p{L}_0-9.\\-]|\x{00B7}|[\x{0300}-\x{036F}]|[\x{203F}-\x{2040}])*/Su',
                $rest,
                $m,
            ) !== 1
        ) {
            throw new InvalidArgumentException(sprintf('Invalid blank node label at position %d.', $pos));
        }

        $label = $m[0];
        if (str_ends_with($label, '.')) {
            $label = substr($label, 0, -1);
        }

        $labelLen = strlen($label);
        if ($labelLen < strlen($rest) && $rest[$labelLen] === ':') {
            throw new InvalidArgumentException(sprintf('Colon not allowed in blank node label at position %d.', $pos));
        }

        $pos += $labelLen;

        return substr($line, $labelStart, $pos - $labelStart);
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
            if ($rest === '' || preg_match('/^[a-zA-Z]+(-[a-zA-Z0-9]+)*/Su', $rest, $m) !== 1) {
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
