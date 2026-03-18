<?php

declare(strict_types=1);

namespace FancyRDF\Formats;

use FancyRDF\Dataset\Quad;
use FancyRDF\Term\BlankNode;
use FancyRDF\Term\Iri;
use FancyRDF\Term\Literal;
use FancyRDF\Uri\UriReference;
use Traversable;
use TypeError;

use function assert;
use function fclose;
use function fgets;
use function hexdec;
use function mb_chr;
use function preg_match;
use function preg_split;
use function rtrim;
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
    public function __construct()
    {
    }

    /**
     * Reads content from the given string.
     *
     * @return Traversable<TripleOrQuadArray>
     */
    public function parse(string $source): Traversable
    {
        $lines = preg_split('/\r\n|\r|\n/', $source, -1, PREG_SPLIT_NO_EMPTY);
        assert($lines !== false, 'failed to split lines from source');

        foreach ($lines as $line) {
            $term = $this->parseLine($line);
            if ($term === null) {
                continue;
            }

            yield $term;
        }
    }

    private string $line = '';
    private int $len     = 0;
    private int $pos     = 0;

    /**
     * Reads content from the given stream.
     *
     * This function closes the stream once it is no longer needed.
     *
     * @param resource $stream
     *   The stream to read from.
     *   The stream will be closed once the parser is done.
     *
     * @return Traversable<TripleOrQuadArray>
     */
    public function parseStream(mixed $stream): Traversable
    {
        try {
            while (true) {
                $line = fgets($stream);
                if ($line === false) {
                    break;
                }

                $line = rtrim($line, "\r\n");

                $term = $this->parseLine($line);
                if ($term === null) {
                    continue;
                }

                yield $term;
            }
        } finally {
            try {
                fclose($stream);
            } catch (TypeError) {
            }
        }
    }

    /**
     * Parses a single N-Quads line into a quad or null.
     *
     * @param string $line
     *   One line (subject predicate object [graph]).
     *
     * @return TripleOrQuadArray|null
     *   The triple, quad, or null if the line is empty or a comment.
     */
    public function parseLine(string $line): array|null
    {
        $this->line = $line;
        $this->len  = strlen($line);
        $this->pos  = 0;

        try {
            $this->skipWhitespace();
            if ($this->pos >= $this->len || $this->line[$this->pos] === '#') {
                return null;
            }

            $subject = $this->parseIriOrBlankNode();
            $this->skipWhitespace();
            assert($this->pos < $this->len, 'unexpected end of line while reading term at position ' . $this->pos);

            $predicate = $this->parseIRI();

            $this->skipWhitespace();
            assert($this->pos < $this->len, 'unexpected end of line while reading term at position ' . $this->pos);

            $object = $this->parseLiteralOrIriOrBlankNode();

            // optionally parse a graph
            $graph = null;
            $this->skipWhitespace();
            if ($this->pos < $this->len && $this->line[$this->pos] !== '.') {
                $graph = $this->parseIriOrBlankNode();
                $this->skipWhitespace();
            }

            // require the trailing dot.
            assert($this->pos < $this->len && $this->line[$this->pos] === '.', 'expected "." at end of statement at position ' . $this->pos);

            return [$subject, $predicate, $object, $graph];
        } finally {
            $this->line = '';
            $this->len  = 0;
            $this->pos  = 0;
        }
    }

    /**
     * Parses an object of a triple.
     */
    private function parseLiteralOrIriOrBlankNode(): Iri|Literal|BlankNode
    {
        // look at the current character and decide what to parse.
        $ch = $this->line[$this->pos] ?? '';
        if ($ch === '"') {
            return $this->parseLiteral();
        }

        return $this->parseIriOrBlankNode();
    }

    private function parseIriOrBlankNode(): Iri|BlankNode
    {
        // look at the current character and decide what to parse.
        $ch = $this->line[$this->pos] ?? '';
        if ($ch === '<') {
            return $this->parseIRI();
        }

        assert($ch === '_' && $this->pos + 1 < $this->len && $this->line[$this->pos + 1] === ':', 'invalid blank node start at position ' . $this->pos);

        return new BlankNode($this->parseBlankNodeLabel());
    }

    private function parseIRI(): Iri
    {
        assert($this->pos < $this->len && $this->line[$this->pos] === '<', 'expected "<" at position ' . $this->pos);

        return $this->parseIriRef();
    }

    /**
     * Skips space and tab.
     */
    private function skipWhitespace(): void
    {
        while ($this->pos < $this->len && ($this->line[$this->pos] === ' ' || $this->line[$this->pos] === "\t")) {
            $this->pos++;
        }
    }

    /**
     * Parses an IRI reference <...>, with \u and \U unescaping.
     */
    private function parseIriRef(): Iri
    {
        assert($this->line[$this->pos] === '<', 'expected "<" at position ' . $this->pos);
        $this->pos++;

        $start = $this->pos;
        $buf   = '';
        while ($this->pos < $this->len) {
            $ch = $this->line[$this->pos];

            // closing '>' found, end of the IRI reference.
            if ($ch === '>') {
                $end = $this->pos;
                $this->pos++;

                $buf .= substr($this->line, $start, $end - $start);
                assert($buf !== '' && $this->isValidRDFIri($buf), 'IRI reference must be a valid absolute IRI: ' . $buf);

                return new Iri($buf);
            }

            // parse an escape sequence.
            if ($ch === '\\' && $this->pos + 1 < $this->len) {
                $buf  .= substr($this->line, $start, $this->pos - $start);
                $buf  .= $this->decodeUchar();
                $start = $this->pos;
                continue;
            }

            // move to the next character.
            $this->pos++;
        }

        // @phpstan-ignore function.impossibleType (GIGO)
        assert(false, 'unclosed IRI reference at position ' . $this->pos);

        // GIGO: Return a random blank node.
        // This branch can only be triggered if assertions are disabled.
        return new Iri('invalid://');
    }

    private function isValidRDFIri(string $iri): bool
    {
        $ref = UriReference::parse($iri);

        return ! $ref->isRelativeReference() && $ref->isRFC3987IriReference();
    }

    /**
     * Parses a blank node label _:label and returns only the label.
     *
     * Label is built on PN_CHARS_BASE, with: _ and [0-9] anywhere; . anywhere except
     * first or last; -, U+00B7, U+0300–U+036F, U+203F–U+2040 anywhere except first.
     * Colon is not allowed (W3C N-Triples).
     *
     * @return non-empty-string
     */
    private function parseBlankNodeLabel(): string
    {
        assert($this->line[$this->pos] === '_' && $this->line[$this->pos + 1] === ':', 'expected _: at position ' . $this->pos);

        $this->pos += 2; // skip the _: prefix

        $start      = $this->pos;
        $matchCount = preg_match(
            '/\G[\p{L}_0-9](?:[\p{L}_0-9.\\-]|\x{00B7}|[\x{0300}-\x{036F}]|[\x{203F}-\x{2040}])*/Su',
            $this->line,
            $m,
            0,
            $this->pos,
        );

        $label = $m[0] ?? '';
        assert($matchCount === 1, 'invalid blank node label at position ' . $this->pos);

        $labelLen = strlen($label);
        if (str_ends_with($label, '.')) {
            $labelLen--;
        }

        $this->pos += $labelLen;

        $label = substr($this->line, $start, $labelLen);
        assert($label !== '', 'empty blank node label at position ' . $this->pos);

        return $label;
    }

    /**
     * Parses a literal: "..." with optional @lang or ^^<datatype>.
     */
    private function parseLiteral(): Literal
    {
        $lexical = $this->parseStringLiteralQuote();
        $this->skipWhitespace();

        $lang     = null;
        $datatype = null;

        if ($this->pos < $this->len && $this->line[$this->pos] === '@') {
            $this->pos++;
            $rest = substr($this->line, $this->pos, $this->len - $this->pos);
            preg_match('/^[a-zA-Z]+(-[a-zA-Z0-9]+)*/Su', $rest, $m);
            $lang = $m[0] ?? '';
            assert($lang !== '', 'missing language tag at position ' . $this->pos);

            $this->pos += strlen($lang);
        } elseif ($this->pos + 1 < $this->len && $this->line[$this->pos] === '^' && $this->line[$this->pos + 1] === '^') {
            $this->pos += 2;
            $datatype   = $this->parseIriRef();
        }

        return new Literal($lexical, $lang, $datatype);
    }

    /**
     * Parses a quoted string "...", with ECHAR and UCHAR unescaping.
     *
     * @return string
     *   The lexical form.
     */
    private function parseStringLiteralQuote(): string
    {
        assert($this->line[$this->pos] === '"', 'expected quote at position ' . $this->pos);

        $this->pos++;

        // Read the string contents.
        $buf   = '';
        $start = $this->pos;
        while ($this->pos < $this->len) {
            $ch = $this->line[$this->pos];

            // Closing quote found, end of string.
            if ($ch === '"') {
                $this->pos++;

                return $buf . substr($this->line, $start, $this->pos - 1 - $start);
            }

            // Parse an escape sequence.
            if ($ch === '\\' && $this->pos + 1 < $this->len) {
                $buf .= substr($this->line, $start, $this->pos - $start);
                $next = $this->line[$this->pos + 1];
                if ($next === 'u' || $next === 'U') {
                    $buf .= $this->decodeUchar();
                } else {
                    $buf       .= $this->decodeEchar($next, $this->pos + 1);
                    $this->pos += 2;
                }

                $start = $this->pos;
                continue;
            }

            // and move to the next character.
            $this->pos++;
        }

        // @phpstan-ignore function.impossibleType (GIGO)
        assert(false, 'unclosed string literal at position ' . $this->pos);

        return '';
    }

    /**
     * Decodes one \uXXXX or \UXXXXXXXX sequence; $pos is advanced.
     */
    private function decodeUchar(): string
    {
        assert($this->line[$this->pos] === '\\', 'expected backslash at position ' . $this->pos);

        $this->pos++;
        assert($this->pos < $this->len, 'unexpected end in escape sequence at position ' . $this->pos);
        assert($this->line[$this->pos] === 'u' || $this->line[$this->pos] === 'U', 'expected \\u or \\U at position ' . $this->pos);

        $u = $this->line[$this->pos] === 'u';
        $this->pos++;

        // determine the number of characters to read.
        $hexLen = $u ? 4 : 8;
        assert($this->pos + $hexLen <= $this->len, 'incomplete \\u or \\U escape at position ' . $this->pos);

        // read the escape sequence.
        $hex = substr($this->line, $this->pos, $hexLen);
        assert(preg_match('/^[0-9A-Fa-f]+$/', $hex) === 1, 'invalid hex in \\u or \\U escape at position ' . $this->pos);

        $this->pos += $hexLen;

        // do the actual decoding.
        $ord = (int) @hexdec($hex);
        assert($ord <= 0x10FFFF, 'code point out of range at position ' . $this->pos);

        return mb_chr($ord, 'UTF-8');
    }

    /**
     * Decodes one ECHAR (single character escape).
     */
    private function decodeEchar(string $char, int $pos): string
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
