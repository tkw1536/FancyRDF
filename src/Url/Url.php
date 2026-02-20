<?php

declare(strict_types=1);

namespace FancySparql\Url;

use function preg_match;
use function str_starts_with;
use function strpos;
use function strrpos;
use function substr;

/**
 * Represents RFC 3986 URIs with parsed components.
 *
 * @see https://www.rfc-editor.org/rfc/rfc3986
 */
final class Url
{
    public function __construct(
        public readonly string|null $scheme,
        public readonly string|null $authority,
        public readonly string $path,
        public readonly string|null $query,
        public readonly string|null $fragment,
    ) {
    }

    /**
     * Parses a URI string into components (no parse_url).
     */
    public static function parse(string $uri): self
    {
        $fragment = null;
        $hashPos  = strpos($uri, '#');
        if ($hashPos !== false) {
            $fragment = substr($uri, $hashPos + 1);
            $uri      = substr($uri, 0, $hashPos);
        }

        $query    = null;
        $queryPos = strpos($uri, '?');
        if ($queryPos !== false) {
            $query = substr($uri, $queryPos + 1);
            $uri   = substr($uri, 0, $queryPos);
        }

        $scheme   = null;
        $hierPart = $uri;
        if (preg_match('#^([a-zA-Z][a-zA-Z0-9+.-]*):(.*)$#s', $uri, $m)) {
            $scheme   = $m[1];
            $hierPart = $m[2];
        }

        $authority = null;
        $path      = '';
        if (str_starts_with($hierPart, '//')) {
            $afterSlash2 = substr($hierPart, 2);
            $nextSlash   = strpos($afterSlash2, '/');
            if ($nextSlash !== false) {
                $authority = substr($afterSlash2, 0, $nextSlash);
                $path      = substr($afterSlash2, $nextSlash);
            } else {
                $authority = $afterSlash2;
                $path      = '';
            }
        } else {
            $path = $hierPart;
        }

        return new self($scheme, $authority, $path, $query, $fragment);
    }

    public function resolveRef(string $ref): self
    {
        return $this->resolve(self::parse($ref));
    }

    /**
     * Resolves a URI reference against this URI (base) per RFC 3986 §5.2.
     */
    public function resolve(Url $ref): self
    {
        if ($ref->scheme !== null) {
            return $ref->normalizePath();
        }

        $scheme    = $this->scheme;
        $authority = $this->authority;

        if ($ref->authority !== null) {
            return (
                new self($scheme, $ref->authority, $ref->path, $ref->query, $ref->fragment)
            )->normalizePath();
        }

        if ($ref->path === '' && $ref->query === null && $ref->fragment === null) {
            return new self($scheme, $authority, $this->path, $this->query, null);
        }

        if ($ref->path === '' && ($ref->query !== null || $ref->fragment !== null)) {
            $path = $this->path;

            return new self($scheme, $authority, $path, $ref->query ?? $this->query, $ref->fragment ?? $this->fragment);
        }

        if (str_starts_with($ref->path, '/')) {
            $path = self::removeDotSegments($ref->path);

            return new self($scheme, $authority, $path, $ref->query, $ref->fragment);
        }

        $mergedPath = $this->mergePath($ref);
        $path       = self::removeDotSegments($mergedPath, $ref->path);

        return new self($scheme, $authority, $path, $ref->query, $ref->fragment);
    }

    /**
     * Implements the Merge Routine from RFC 3986 §5.2.3.
     *
     * @see https://www.rfc-editor.org/rfc/rfc3986#section-5.2.3
     * Merges base path with reference path per RFC 3986 §5.2.3.
     */
    private function mergePath(Url $ref): string
    {
        // If the base URI has a defined authority component and an empty
        // path, then return a string consisting of "/" concatenated with the
        // reference's path
        if ($this->authority !== null && ($this->path === '' || $this->path === '/')) {
            return '/' . $ref->path;
        }

        // return a string consisting of the reference's path component
        // appended to all but the last segment of the base URI's path (i.e.,
        // excluding any characters after the right-most "/" in the base URI
        // path, or excluding the entire base URI path if it does not contain
        // any "/" characters).
        $lastSlash = strrpos($this->path, '/');
        if ($lastSlash === false) {
            return $ref->path;
        }

        return substr($this->path, 0, $lastSlash + 1) . $ref->path;
    }

    /**
     * Implements the Remove Dot Segment routine from RFC 3986 §5.2.4.
     *
     * @see https://www.rfc-editor.org/rfc/rfc3986#section-5.2.4
     */
    private static function removeDotSegments(string $path, string $refPath = ''): string
    {
        // 1.  The input buffer is initialized with the now-appended path
        // components and the output buffer is initialized to the empty
        // string.
        $input  = $path;
        $output = '';

        // 2.  While the input buffer is not empty, loop as follows:
        while ($input !== '') {
            // A.  If the input buffer begins with a prefix of "../" or "./",
            // then remove that prefix from the input buffer
            if (str_starts_with($input, '../')) {
                $input = substr($input, 3);
                continue;
            }

            if (str_starts_with($input, './')) {
                $input = substr($input, 2);
                continue;
            }

            // B.  if the input buffer begins with a prefix of "/./" or "/.",
            // where "." is a complete path segment, then replace that
            // prefix with "/" in the input buffer
            if (str_starts_with($input, '/./')) {
                $input = '/' . substr($input, 3);
                continue;
            }

            if ($input === '/.') {
                $input = '/';
                continue;
            }

            // C.  if the input buffer begins with a prefix of "/../" or "/..",
            // where ".." is a complete path segment, then replace that
            // prefix with "/" in the input buffer and remove the last
            // segment and its preceding "/" (if any) from the output
            // buffer
            if (str_starts_with($input, '/../')) {
                $input = '/' . substr($input, 4);
                $last  = strrpos($output, '/');
                if ($last !== false) {
                    $output = substr($output, 0, $last);
                }

                continue;
            }

            if ($input === '/..') {
                $input = '/';
                $last  = strrpos($output, '/');
                if ($last !== false) {
                    $output = substr($output, 0, $last);
                }

                continue;
            }

            // D.  if the input buffer consists only of "." or "..", then remove
            // that from the input buffer
            if ($input === '.' || $input === '..') {
                $input = '';
                continue;
            }

            // E.  move the first path segment in the input buffer to the end of
            // the output buffer, including the initial "/" character (if
            // any) and any subsequent characters up to, but not including,
            // the next "/" character or the end of the input buffer
            if (str_starts_with($input, '/')) {
                $nextSlash = strpos($input, '/', 1);
                if ($nextSlash !== false) {
                    $segment = substr($input, 0, $nextSlash);
                    $input   = substr($input, $nextSlash);
                } else {
                    $segment = $input;
                    $input   = '';
                }

                $output .= $segment;
                continue;
            }

            $nextSlash = strpos($input, '/');
            if ($nextSlash !== false) {
                $segment = substr($input, 0, $nextSlash);
                $input   = substr($input, $nextSlash);
            } else {
                $segment = $input;
                $input   = '';
            }

            $output .= $segment;
        }

        // 3.  Finally, the output buffer is returned as the result of
        // remove_dot_segments.
        return $output;
    }

    private function normalizePath(): self
    {
        $path = self::removeDotSegments($this->path, $this->path);

        return new self($this->scheme, $this->authority, $path, $this->query, $this->fragment);
    }

    /**
     * Turns this URL into a URL reference string.
     *
     * This implements the component Recomposition routine from RFC 3986 §5.3.
     *
     * @see https://www.rfc-editor.org/rfc/rfc3986#section-5.3
     */
    public function toString(): string
    {
        $result = '';
        if ($this->scheme !== null) {
            $result .= $this->scheme . ':';
        }

        if ($this->authority !== null) {
            $result .= '//' . $this->authority;
        }

        $result .= $this->path;
        if ($this->query !== null) {
            $result .= '?' . $this->query;
        }

        if ($this->fragment !== null) {
            $result .= '#' . $this->fragment;
        }

        return $result;
    }

    /**
     * Resolves a URI against a base URI according to RFC 3986.
     *
     * @param string $base The base URI to resolve against.
     * @param string $uri  The URI to resolve (which may be empty, relative, or absolute)
     *
     * @return string The resolved URI. Whenever possible, this is an absolute URI.
     */
    public static function parseAndResolve(string $base, string $uri): string
    {
        if ($base === '') {
            return $uri;
        }

        return self::parse($base)->resolve(self::parse($uri))->toString();
    }
}
