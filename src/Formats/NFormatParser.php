<?php

declare(strict_types=1);

namespace FancySparql\Formats;

use FancySparql\Graph\Quad;
use FancySparql\Term\Literal;
use FancySparql\Term\Resource;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;
use Traversable;

use function assert;
use function ctype_xdigit;
use function hexdec;
use function mb_chr;
use function preg_match;
use function preg_split;
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
 * This guarantee DOES NOT apply negatively: Invalid terms may serialize into invalid Term instances,
 * may parse into completely unrelated terms, or may throw.
 * In practice, the code makes use of assert calls to check validity, and may throw for certain invalid
 * terms iff assertions are enabled.
 *
 * @see https://www.w3.org/TR/n-triples/
 * @see https://www.w3.org/TR/n-quads/
 * @see https://www.w3.org/TR/rdf11-testcases/
 * @see https://www.php.net/manual/en/function.assert.php
 *
 * @phpstan-import-type TripleOrQuadArray from Quad
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
     * @return Traversable<TripleOrQuadArray>
     */
    public static function parse(string $source): Traversable
    {
        $lines = preg_split('/\r\n|\r|\n/', $source, -1, PREG_SPLIT_NO_EMPTY);
        assert($lines !== false, 'failed to split lines from source');

        foreach ($lines as $line) {
            $term = self::parseLine($line);
            if ($term === null) {
                continue;
            }

            yield $term;
        }
    }

    /**
     * Reads content from the given stream.
     *
     * This function closes the stream once it is no longer needed.
     *
     * @return Traversable<TripleOrQuadArray>
     */
    public static function parseStream(StreamInterface $stream): Traversable
    {
        try {
            while (true) {
                $line = Utils::readLine($stream);
                if ($line === '') {
                    break;
                }

                $term = self::parseLine($line);
                if ($term === null) {
                    continue;
                }

                yield $term;
            }
        } finally {
            $stream->close();
        }
    }

  /**
   * Parses a single N-Quads line into a quad or null.
   *
   * @param string $line
   *   One line (subject predicate object [graph] .).
   *
   * @return TripleOrQuadArray|null
   *   The triple, quad, or null if the line is empty or a comment.
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
        assert($subject instanceof Resource, 'subject must be a resource at position ' . $pos);

        self::skipWhitespace($line, $pos, $len);
        assert($pos < $len, 'unexpected end of line while reading term at position ' . $pos);

        $predicate = self::parseTerm($line, $pos, $len);
        assert($predicate instanceof Resource && ! $predicate->isBlankNode(), 'predicate must be an IRI at position ' . $pos);

        self::skipWhitespace($line, $pos, $len);
        assert($pos < $len, 'unexpected end of line while reading term at position ' . $pos);

        $object = self::parseTerm($line, $pos, $len);

        // optionally parse a graph
        $graph = null;
        self::skipWhitespace($line, $pos, $len);
        if ($pos < $len && $line[$pos] !== '.') {
            $graph = self::parseTerm($line, $pos, $len);
            assert($graph instanceof Resource, 'graph must be a resource at position ' . $pos);

            self::skipWhitespace($line, $pos, $len);
        }

        // require the trailing dot.
        assert($pos < $len && $line[$pos] === '.', 'expected "." at end of statement at position ' . $pos);

        return [$subject, $predicate, $object, $graph];
    }

  /**
   * Parses a single RDF term (IRI, blank node, or literal).
   */
    private static function parseTerm(string $line, int &$pos, int $len): Literal|Resource
    {
        // look at the current character and decide what to parse.
        $ch = $line[$pos] ?? '';
        if ($ch === '<') {
            return new Resource(self::parseIriRef($line, $pos, $len));
        }

        if ($ch === '_' && $pos + 1 < $len && $line[$pos + 1] === ':') {
            return new Resource(self::parseBlankNodeLabel($line, $pos, $len));
        }

        assert($ch === '"', 'invalid term start: must be "<" or "_:" or "" at position ' . $pos);

        return self::parseLiteral($line, $pos, $len);
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
   * @return non-empty-string
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

                $buf .= $buf . substr($line, $start, $end - $start);
                assert($buf !== '', 'empty IRI reference at position ' . $pos);

                return $buf;
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

        // @phpstan-ignore function.impossibleType (GIGO)
        assert(false, 'unclosed IRI reference at position ' . $pos);

        // GIGO: Return a random blank node.
        // This branch can only be triggered if assertions are disabled.
        return '_:gigo';
    }

  /**
   * Parses a blank node label _:label.
   *
   * Label is built on PN_CHARS_BASE, with: _ and [0-9] anywhere; . anywhere except
   * first or last; -, U+00B7, U+0300–U+036F, U+203F–U+2040 anywhere except first.
   * Colon is not allowed (W3C N-Triples).
   *
   * @return non-empty-string
   */
    private static function parseBlankNodeLabel(string $line, int &$pos, int $len): string
    {
        assert($line[$pos] === '_' && $line[$pos + 1] === ':', 'expected _: at position ' . $pos);

        $start = $pos;
        $pos  += 2; // skip the _: prefix

        $rest = substr($line, $pos, $len - $pos);
        assert($rest !== '', 'empty blank node label at position ' . $pos);
        $matchCount = preg_match(
            '/^[\p{L}_0-9](?:[\p{L}_0-9.\\-]|\x{00B7}|[\x{0300}-\x{036F}]|[\x{203F}-\x{2040}])*/Su',
            $rest,
            $m,
        );

        $label = $m[0] ?? '';
        assert($matchCount === 1, 'invalid blank node label at position ' . $pos);

        if (str_ends_with($label, '.')) {
            $label = substr($label, 0, -1);
        }

        $labelLen = strlen($label);
        assert($labelLen >= strlen($rest) || $rest[$labelLen] !== ':', 'colon not allowed in blank node label at position ' . $pos);

        $pos += $labelLen;

        $result = substr($line, $start, $pos - $start);
        assert($result !== '', 'empty blank node label at position ' . $pos);

        return $result;
    }

  /**
   * Parses a literal: "..." with optional @lang or ^^<datatype>.
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
            preg_match('/^[a-zA-Z]+(-[a-zA-Z0-9]+)*/Su', $rest, $m);
            $lang = $m[0] ?? '';
            assert($lang !== '', 'missing language tag at position ' . $pos);

            $pos += strlen($lang);
        } elseif ($pos + 1 < $len && $line[$pos] === '^' && $line[$pos + 1] === '^') {
            $pos     += 2;
            $datatype = self::parseIriRef($line, $pos, $len);
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
        assert($line[$pos] === '"', 'expected quote at position ' . $pos);

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
                    $buf .= self::decodeEchar($next, $pos + 1);
                    $pos += 2;
                }

                $start = $pos;
                continue;
            }

            // and move to the next character.
            $pos++;
        }

        // @phpstan-ignore function.impossibleType (GIGO)
        assert(false, 'unclosed string literal at position ' . $pos);

        return '';
    }

  /**
   * Decodes one \uXXXX or \UXXXXXXXX sequence; $pos is advanced.
   */
    private static function decodeUchar(string $line, int &$pos, int $len): string
    {
        assert($line[$pos] === '\\', 'expected backslash at position ' . $pos);

        $pos++;
        assert($pos < $len, 'unexpected end in escape sequence at position ' . $pos);
        assert($line[$pos] === 'u' || $line[$pos] === 'U', 'expected \\u or \\U at position ' . $pos);

        $u = $line[$pos] === 'u';
        $pos++;

        // determine the number of characters to read.
        $hexLen = $u ? 4 : 8;
        assert($pos + $hexLen <= $len, 'incomplete \\u or \\U escape at position ' . $pos);

        // read the escape sequence.
        $hex = substr($line, $pos, $hexLen);
        assert(ctype_xdigit($hex), 'invalid hex in \\u or \\U escape at position ' . $pos);

        $pos += $hexLen;

        // do the actual decoding.
        $ord = (int) hexdec($hex);
        assert($ord <= 0x10FFFF, 'code point out of range at position ' . $pos);

        return mb_chr($ord, 'UTF-8');
    }

  /**
   * Decodes one ECHAR (single character escape).
   */
    private static function decodeEchar(string $char, int $pos): string
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
            default => (static function () use ($pos): string {
                // @phpstan-ignore function.impossibleType (GIGO)
                assert(false, 'invalid escape sequence at position ' . $pos);

                return '';
            })()
        };
    }
}
