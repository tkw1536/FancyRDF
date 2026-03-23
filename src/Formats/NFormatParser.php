<?php

declare(strict_types=1);

namespace FancyRDF\Formats;

use FancyRDF\Dataset\Quad;
use FancyRDF\Exceptions\NonCompliantInputError;
use FancyRDF\Term\BlankNode;
use FancyRDF\Term\Datatype\LangString;
use FancyRDF\Term\Iri;
use FancyRDF\Term\Literal;
use FancyRDF\Uri\UriReference;
use RuntimeException;
use Traversable;

use function fclose;
use function fgets;
use function hexdec;
use function is_resource;
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
    /**
     * Constructs a new NFormatParser.
     *
     * @param bool $strict
     *   Enable strict mode.
     *   In strict mode, additional checks are performed to validate compliance with the standard, and appropriate NonCompliantInputErrors may be thrown.
     */
    public function __construct(public readonly bool $strict)
    {
    }

    /**
     * Reads content from the given string.
     *
     * @return Traversable<TripleOrQuadArray>
     *
     * @throws NonCompliantInputError if strict mode is enabled and the input is not compliant with the standard.
     * @throws RuntimeException
     */
    public function parse(string $source): Traversable
    {
        $lines = preg_split('/\r\n|\r|\n/', $source, -1, PREG_SPLIT_NO_EMPTY);
        if ($lines === false) {
            throw new RuntimeException('failed to split lines from source');
        }

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
     *
     * @throws NonCompliantInputError if strict mode is enabled and the input is not compliant with the standard.
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
            if (is_resource($stream)) {
                fclose($stream);
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
     *
     * @throws NonCompliantInputError
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
            if ($this->strict && $this->pos >= $this->len) {
                throw new NonCompliantInputError('unexpected end of line while reading term at position ' . $this->pos);
            }

            $predicate = $this->parseIRI();

            $this->skipWhitespace();
            if ($this->strict && $this->pos >= $this->len) {
                throw new NonCompliantInputError('unexpected end of line while reading term at position ' . $this->pos);
            }

            $object = $this->parseLiteralOrIriOrBlankNode();

            // optionally parse a graph
            $graph = null;
            $this->skipWhitespace();
            if ($this->pos < $this->len && $this->line[$this->pos] !== '.') {
                $graph = $this->parseIriOrBlankNode();
                $this->skipWhitespace();
            }

            // require the trailing dot.
            if ($this->strict && ! ($this->pos < $this->len && $this->line[$this->pos] === '.')) {
                throw new NonCompliantInputError('expected "." at end of statement at position ' . $this->pos);
            }

            return [$subject, $predicate, $object, $graph];
        } finally {
            $this->line = '';
            $this->len  = 0;
            $this->pos  = 0;
        }
    }

    /**
     * Parses an object of a triple.
     *
     * @throws NonCompliantInputError
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

    /** @throws NonCompliantInputError */
    private function parseIriOrBlankNode(): Iri|BlankNode
    {
        // look at the current character and decide what to parse.
        $ch = $this->line[$this->pos] ?? '';
        if ($ch === '<') {
            return $this->parseIRI();
        }

        if ($this->strict && ! ($ch === '_' && $this->pos + 1 < $this->len && $this->line[$this->pos + 1] === ':')) {
            throw new NonCompliantInputError('invalid blank node start at position ' . $this->pos);
        }

        return new BlankNode($this->parseBlankNodeLabel());
    }

    /** @throws NonCompliantInputError */
    private function parseIRI(): Iri
    {
        if ($this->strict && ! ($this->pos < $this->len && $this->line[$this->pos] === '<')) {
            throw new NonCompliantInputError('expected "<" at position ' . $this->pos);
        }

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
     *
     * @throws NonCompliantInputError
     */
    private function parseIriRef(): Iri
    {
        if ($this->strict && ! ($this->line[$this->pos] === '<')) {
            throw new NonCompliantInputError('expected "<" at position ' . $this->pos);
        }

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
                if ($this->strict && $buf !== '' && ! $this->isValidRDFIri($buf)) {
                    throw new NonCompliantInputError('invalid IRI reference at position ' . $this->pos);
                }

                return $buf !== '' ? new Iri($buf) : $this->fallbackIRI('empty IRI reference');
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

        return $this->fallbackIRI('unclosed IRI reference');
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
     *
     * @throws NonCompliantInputError
     */
    private function parseBlankNodeLabel(): string
    {
        if ($this->strict && ! ($this->line[$this->pos] === '_' && $this->line[$this->pos + 1] === ':')) {
            throw new NonCompliantInputError('expected _: at position ' . $this->pos);
        }

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
        if ($this->strict && ! ($matchCount === 1)) {
            throw new NonCompliantInputError('invalid blank node label at position ' . $this->pos);
        }

        $labelLen = strlen($label);
        if (str_ends_with($label, '.')) {
            $labelLen--;
        }

        $this->pos += $labelLen;

        $label = substr($this->line, $start, $labelLen);
        if ($label === '') {
            if ($this->strict) {
                throw new NonCompliantInputError('empty blank node label at position ' . $this->pos);
            }

            // Technically there isn't a valid blank node label here.
            // But we do best-effort parsing, so return something that is vaguely sensible.
            return '_';
        }

        return $label;
    }

    /**
     * Parses a literal: "..." with optional @lang or ^^<datatype>.
     *
     * @throws NonCompliantInputError
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
            if ($lang === '') {
                if ($this->strict) {
                    throw new NonCompliantInputError('missing language tag at position ' . $this->pos);
                }

                return Literal::XSDString($lexical);
            }

            $this->pos += strlen($lang);

            return Literal::langString($lexical, $lang);
        }

        if ($this->pos + 1 < $this->len && $this->line[$this->pos] === '^' && $this->line[$this->pos + 1] === '^') {
            $this->pos += 2;
            $datatype   = $this->parseIriRef();

            if ($datatype->iri === LangString::IRI) {
                if ($this->strict) {
                    throw new NonCompliantInputError('LangString literals cannot be created with a datatype IRI at position ' . $this->pos);
                }

                return Literal::XSDString($lexical);
            }

            return Literal::typed($lexical, $datatype->iri);
        }

        return Literal::XSDString($lexical);
    }

    /**
     * Parses a quoted string "...", with ECHAR and UCHAR unescaping.
     *
     * @return string
     *   The lexical form.
     *
     * @throws NonCompliantInputError
     */
    private function parseStringLiteralQuote(): string
    {
        if ($this->strict && ! ($this->line[$this->pos] === '"')) {
            throw new NonCompliantInputError('expected quote at position ' . $this->pos);
        }

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

        if ($this->strict) {
            throw new NonCompliantInputError('unclosed string literal at position ' . $this->pos);
        }

        return '';
    }

    /**
     * Decodes one \uXXXX or \UXXXXXXXX sequence; $pos is advanced.
     *
     * @throws NonCompliantInputError
     */
    private function decodeUchar(): string
    {
        if ($this->strict && ! ($this->line[$this->pos] === '\\')) {
            throw new NonCompliantInputError('expected backslash at position ' . $this->pos);
        }

        $this->pos++;
        if ($this->strict && ! ($this->pos < $this->len)) {
            throw new NonCompliantInputError('unexpected end in escape sequence at position ' . $this->pos);
        }

        if ($this->strict && ! ($this->line[$this->pos] === 'u' || $this->line[$this->pos] === 'U')) {
            throw new NonCompliantInputError('expected \\u or \\U at position ' . $this->pos);
        }

        $u = $this->line[$this->pos] === 'u';
        $this->pos++;

        // determine the number of characters to read.
        $hexLen = $u ? 4 : 8;
        if ($this->strict && ! ($this->pos + $hexLen <= $this->len)) {
            throw new NonCompliantInputError('incomplete \\u or \\U escape at position ' . $this->pos);
        }

        // read the escape sequence.
        $hex = substr($this->line, $this->pos, $hexLen);
        if ($this->strict && ! (preg_match('/^[0-9A-Fa-f]+$/', $hex) === 1)) {
            throw new NonCompliantInputError('invalid hex in \\u or \\U escape at position ' . $this->pos);
        }

        $this->pos += $hexLen;

        // do the actual decoding.
        $ord = (int) @hexdec($hex);
        if ($this->strict && $ord > 0x10FFFF) {
            throw new NonCompliantInputError('code point out of range at position ' . $this->pos);
        }

        return mb_chr($ord, 'UTF-8');
    }

    /**
     * Decodes one ECHAR (single character escape).
     *
     * @throws NonCompliantInputError
     */
    private function decodeEchar(string $char, int $pos): string
    {
        $result = match ($char) {
            't' => "\t",
            'b' => "\x08",
            'n' => "\n",
            'r' => "\r",
            'f' => "\f",
            '"' => '"',
            '\'' => "'",
            '\\' => '\\',
            default => '',
        };

        if ($this->strict && $result === '') {
            throw new NonCompliantInputError('invalid escape sequence at position ' . $pos);
        }

        return $result;
    }

    /**
     * If in strict mode, throws a NonCompliantInputError with the given message.
     * If in loose mode, returns an IRI that can be used as a placeholder.
     *
     * @throws NonCompliantInputError
     */
    private function fallbackIRI(string $message): Iri
    {
        if ($this->strict) {
            throw new NonCompliantInputError($message . ' at position ' . $this->pos);
        }

        return new Iri('invalid://');
    }
}
