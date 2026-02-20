<?php

declare(strict_types=1);

namespace FancySparql\Url;

use function preg_match;
use function str_ends_with;
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

        $mergedPath = self::mergePath($this->scheme, $this->authority, $this->path, $ref->path);
        $path       = self::removeDotSegments($mergedPath, $ref->path);

        return new self($scheme, $authority, $path, $ref->query, $ref->fragment);
    }

    /**
     * Merges base path with reference path per RFC 3986 §5.2.3.
     */
    private static function mergePath(string|null $scheme, string|null $authority, string $basePath, string $refPath): string
    {
        if ($authority !== null && ($basePath === '' || $basePath === '/')) {
            return '/' . $refPath;
        }

        $lastSlash = strrpos($basePath, '/');
        if ($lastSlash === false) {
            return $refPath;
        }

        return substr($basePath, 0, $lastSlash + 1) . $refPath;
    }

    /**
     * Removes . and .. segments per RFC 3986 §5.2.4.
     * Preserves trailing slash when refPath indicates a directory (e.g. ends with / or is . or ..).
     */
    private static function removeDotSegments(string $path, string $refPath = ''): string
    {
        $trailingSlash = $path === '' || $path === '/' || str_ends_with($path, '/')
            || $refPath === '.' || $refPath === './' || $refPath === '..' || $refPath === '../'
            || str_ends_with($refPath, '/');

        $input  = $path;
        $output = '';

        while ($input !== '') {
            if (str_starts_with($input, '../')) {
                $input = substr($input, 3);
                continue;
            }

            if (str_starts_with($input, './')) {
                $input = substr($input, 2);
                continue;
            }

            if (str_starts_with($input, '/./')) {
                $input = '/' . substr($input, 3);
                continue;
            }

            if ($input === '/.') {
                $input = '/';
                continue;
            }

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

            if ($input === '.' || $input === '..') {
                $input = '';
                continue;
            }

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

        if ($trailingSlash && $output !== '' && ! str_ends_with($output, '/')) {
            $output .= '/';
        }

        return $output;
    }

    private function normalizePath(): self
    {
        $path = self::removeDotSegments($this->path, $this->path);

        return new self($this->scheme, $this->authority, $path, $this->query, $this->fragment);
    }

    /**
     * Turns this URL into a string that can be used to parse it again.
     */
    public function toString(): string
    {
        $s = '';
        if ($this->scheme !== null) {
            $s .= $this->scheme . ':';
        }

        if ($this->authority !== null) {
            $s .= '//' . $this->authority;
        }

        $s .= $this->path;
        if ($this->query !== null) {
            $s .= '?' . $this->query;
        }

        if ($this->fragment !== null) {
            $s .= '#' . $this->fragment;
        }

        return $s;
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
