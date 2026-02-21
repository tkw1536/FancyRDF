<?php

declare(strict_types=1);

namespace FancyRDF\Formats;

use FancyRDF\Dataset\Quad;
use FancyRDF\Term\Literal;
use FancyRDF\Term\Resource;
use FancyRDF\Uri\UriReference;
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

    private static string $line = '';
    private static int $len     = 0;
    private static int $pos     = 0;

  /**
   * Parses a single N-Quads line into a quad or null.
   *
   * @param string $line
   *   One line (subject predicate object [graph]).
   *
   * @return TripleOrQuadArray|null
   *   The triple, quad, or null if the line is empty or a comment.
   */
    public static function parseLine(string $line): array|null
    {
        self::$line = $line;
        self::$len  = strlen($line);
        self::$pos  = 0;

        try {
            self::skipWhitespace();
            if (self::$pos >= self::$len || self::$line[self::$pos] === '#') {
                return null;
            }

            $subject = self::parseAnyResource();
            self::skipWhitespace();
            assert(self::$pos < self::$len, 'unexpected end of line while reading term at position ' . self::$pos);

            $predicate = self::parseIRI();
            assert(! $predicate->isBlankNode(), 'predicate must be an IRI at position ' . self::$pos);

            self::skipWhitespace();
            assert(self::$pos < self::$len, 'unexpected end of line while reading term at position ' . self::$pos);

            $object = self::parseAnyTerm();

            // optionally parse a graph
            $graph = null;
            self::skipWhitespace();
            if (self::$pos < self::$len && self::$line[self::$pos] !== '.') {
                $graph = self::parseAnyResource();
                self::skipWhitespace();
            }

            // require the trailing dot.
            assert(self::$pos < self::$len && self::$line[self::$pos] === '.', 'expected "." at end of statement at position ' . self::$pos);

            return [$subject, $predicate, $object, $graph];
        } finally {
            self::$line = '';
            self::$len  = 0;
            self::$pos  = 0;
        }
    }

    /**
     * Parses an object of a triple.
     */
    private static function parseAnyTerm(): Literal|Resource
    {
        // look at the current character and decide what to parse.
        $ch = self::$line[self::$pos] ?? '';
        if ($ch === '"') {
            return self::parseLiteral();
        }

        return self::parseAnyResource();
    }

    private static function parseAnyResource(): Resource
    {
        // look at the current character and decide what to parse.
        $ch = self::$line[self::$pos] ?? '';
        if ($ch === '<') {
            return self::parseIRI();
        }

        assert($ch === '_' && self::$pos + 1 < self::$len && self::$line[self::$pos + 1] === ':', 'invalid blank node start at position ' . self::$pos);

        return new Resource(self::parseBlankNodeLabel());
    }

    private static function parseIRI(): Resource
    {
        assert(self::$pos < self::$len && self::$line[self::$pos] === '<', 'expected "<" at position ' . self::$pos);

        return new Resource(self::parseIriRef());
    }

  /**
   * Skips space and tab.
   */
    private static function skipWhitespace(): void
    {
        while (self::$pos < self::$len && (self::$line[self::$pos] === ' ' || self::$line[self::$pos] === "\t")) {
            self::$pos++;
        }
    }

  /**
   * Parses an IRI reference <...>, with \u and \U unescaping.
   *
   * @return non-empty-string
   *   The IRI string.
   */
    private static function parseIriRef(): string
    {
        assert(self::$line[self::$pos] === '<', 'expected "<" at position ' . self::$pos);
        self::$pos++;

        $start = self::$pos;
        $buf   = '';
        while (self::$pos < self::$len) {
            $ch = self::$line[self::$pos];

            // closing '>' found, end of the IRI reference.
            if ($ch === '>') {
                $end = self::$pos;
                self::$pos++;

                $buf .= substr(self::$line, $start, $end - $start);
                assert(!UriReference::parse($buf)->isRelativeReference(), 'IRI reference must not be relative');

                return $buf;
            }

            // parse an escape sequence.
            if ($ch === '\\' && self::$pos + 1 < self::$len) {
                $buf  .= substr(self::$line, $start, self::$pos - $start);
                $buf  .= self::decodeUchar();
                $start = self::$pos;
                continue;
            }

            // move to the next character.
            self::$pos++;
        }

        // @phpstan-ignore function.impossibleType (GIGO)
        assert(false, 'unclosed IRI reference at position ' . self::$pos);

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
    private static function parseBlankNodeLabel(): string
    {
        assert(self::$line[self::$pos] === '_' && self::$line[self::$pos + 1] === ':', 'expected _: at position ' . self::$pos);

        $start      = self::$pos;
        self::$pos += 2; // skip the _: prefix

        $matchCount = preg_match(
            '/\G[\p{L}_0-9](?:[\p{L}_0-9.\\-]|\x{00B7}|[\x{0300}-\x{036F}]|[\x{203F}-\x{2040}])*/Su',
            self::$line,
            $m,
            0,
            self::$pos,
        );

        $label = $m[0] ?? '';
        assert($matchCount === 1, 'invalid blank node label at position ' . self::$pos);

        $labelLen = strlen($label);
        if (str_ends_with($label, '.')) {
            $labelLen--;
        }

        self::$pos += $labelLen;

        $label = substr(self::$line, $start, $labelLen + 2);
        assert($label !== '', 'empty blank node label at position ' . self::$pos);

        return $label;
    }

  /**
   * Parses a literal: "..." with optional @lang or ^^<datatype>.
   */
    private static function parseLiteral(): Literal
    {
        $lexical = self::parseStringLiteralQuote();
        self::skipWhitespace();

        $lang     = null;
        $datatype = null;

        if (self::$pos < self::$len && self::$line[self::$pos] === '@') {
            self::$pos++;
            $rest = substr(self::$line, self::$pos, self::$len - self::$pos);
            preg_match('/^[a-zA-Z]+(-[a-zA-Z0-9]+)*/Su', $rest, $m);
            $lang = $m[0] ?? '';
            assert($lang !== '', 'missing language tag at position ' . self::$pos);

            self::$pos += strlen($lang);
        } elseif (self::$pos + 1 < self::$len && self::$line[self::$pos] === '^' && self::$line[self::$pos + 1] === '^') {
            self::$pos += 2;
            $datatype   = self::parseIriRef();
        }

        return new Literal($lexical, $lang, $datatype);
    }

  /**
   * Parses a quoted string "...", with ECHAR and UCHAR unescaping.
   *
   * @return string
   *   The lexical form.
   */
    private static function parseStringLiteralQuote(): string
    {
        assert(self::$line[self::$pos] === '"', 'expected quote at position ' . self::$pos);

        self::$pos++;

        // Read the string contents.
        $buf   = '';
        $start = self::$pos;
        while (self::$pos < self::$len) {
            $ch = self::$line[self::$pos];

            // Closing quote found, end of string.
            if ($ch === '"') {
                self::$pos++;

                return $buf . substr(self::$line, $start, self::$pos - 1 - $start);
            }

            // Parse an escape sequence.
            if ($ch === '\\' && self::$pos + 1 < self::$len) {
                $buf .= substr(self::$line, $start, self::$pos - $start);
                $next = self::$line[self::$pos + 1];
                if ($next === 'u' || $next === 'U') {
                    $buf .= self::decodeUchar();
                } else {
                    $buf       .= self::decodeEchar($next, self::$pos + 1);
                    self::$pos += 2;
                }

                $start = self::$pos;
                continue;
            }

            // and move to the next character.
            self::$pos++;
        }

        // @phpstan-ignore function.impossibleType (GIGO)
        assert(false, 'unclosed string literal at position ' . self::$pos);

        return '';
    }

  /**
   * Decodes one \uXXXX or \UXXXXXXXX sequence; $pos is advanced.
   */
    private static function decodeUchar(): string
    {
        assert(self::$line[self::$pos] === '\\', 'expected backslash at position ' . self::$pos);

        self::$pos++;
        assert(self::$pos < self::$len, 'unexpected end in escape sequence at position ' . self::$pos);
        assert(self::$line[self::$pos] === 'u' || self::$line[self::$pos] === 'U', 'expected \\u or \\U at position ' . self::$pos);

        $u = self::$line[self::$pos] === 'u';
        self::$pos++;

        // determine the number of characters to read.
        $hexLen = $u ? 4 : 8;
        assert(self::$pos + $hexLen <= self::$len, 'incomplete \\u or \\U escape at position ' . self::$pos);

        // read the escape sequence.
        $hex = substr(self::$line, self::$pos, $hexLen);
        assert(ctype_xdigit($hex), 'invalid hex in \\u or \\U escape at position ' . self::$pos);

        self::$pos += $hexLen;

        // do the actual decoding.
        $ord = (int) hexdec($hex);
        assert($ord <= 0x10FFFF, 'code point out of range at position ' . self::$pos);

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
