<?php

declare(strict_types=1);

namespace FancyRDF\Uri;

use RuntimeException;

use function assert;
use function chr;
use function hexdec;
use function is_numeric;
use function preg_match;
use function preg_replace_callback;
use function str_starts_with;
use function strpos;
use function strrpos;
use function strtolower;
use function strtoupper;
use function substr;

/**
 * Represents an RFC 3986 URI  or RFC 3987 IRI References.
 *
 * @see https://www.rfc-editor.org/rfc/rfc3986
 * @see https://www.rfc-editor.org/rfc/rfc3987
 */
final class UriReference
{
    /**
     * Constructs a new URI or IRI Reference from it's syntactical components as described in
     * section 3 of RFC 3986 or section 2 of RFC 3987.
     *
     * Both standards use a similar syntax, and their only difference is in their set of allowed characters.
     * To check if an otherwise syntactically correct instance of this class only contains allowed correct characters use
     * the isRFC3986UriReference() or isRFC3987IriReference() methods.
     *
     * All other methods equally to both URI and IRI references.
     *
     * @see https://www.rfc-editor.org/rfc/rfc3986#section-3
     * @see https://www.rfc-editor.org/rfc/rfc3987#section-2
     *
     * @param non-empty-string|null $scheme
     * @param non-empty-string|null $authority
     * @param non-empty-string|null $query
     * @param non-empty-string|null $fragment
     */
    public function __construct(
        public readonly string|null $scheme,
        public readonly string|null $authority,
        public readonly string $path,
        public readonly string|null $query,
        public readonly string|null $fragment,
    ) {
        // TODO: Add a method that we only have valid characters in each of the components.
    }

    // ===========================
    // URI vs IRI
    // ===========================

    /**
     * Checks if this is a valid RFC 3986 URI reference.
     *
     * Validates that all components contain only ASCII characters allowed by RFC 3986 §2
     * (unreserved, gen-delims, sub-delims, and pct-encoded).
     *
     * @see https://www.rfc-editor.org/rfc/rfc3986#section-2
     */
    public function isRFC3986UriReference(): bool
    {
        $components = [
            $this->scheme,
            $this->authority,
            $this->path,
            $this->query,
            $this->fragment,
        ];

        foreach ($components as $component) {
            if ($component === null) {
                continue;
            }

            if (! self::componentIsValidRfc3986($component)) {
                return false;
            }
        }

        return $this->authorityPartsValidRfc3986();
    }

    /**
     * Checks if this is a valid RFC 3987 IRI reference.
     *
     * Validates that all components contain only characters allowed by RFC 3987 §2.2
     * (iunreserved, gen-delims, sub-delims, pct-encoded, and in query/fragment also iprivate).
     *
     * @see https://www.rfc-editor.org/rfc/rfc3987#section-2.2
     */
    public function isRFC3987IriReference(): bool
    {
        $components = [
            $this->scheme,
            $this->authority,
            $this->path,
            $this->query,
            $this->fragment,
        ];

        foreach ($components as $component) {
            if ($component === null) {
                continue;
            }

            if (! self::componentIsValidRfc3987($component)) {
                return false;
            }
        }

        return $this->authorityPartsValidRfc3987();
    }

    /**
     * RFC 3986 §2: URI component must be ASCII-only and only contain unreserved, gen-delims, sub-delims, pct-encoded.
     */
    private static function componentIsValidRfc3986(string $component): bool
    {
        if (preg_match('/[\x80-\xff]/', $component) === 1) {
            return false;
        }

        return preg_match(self::RFC3986_COMPONENT_PATTERN, $component) === 1;
    }

    /**
     * RFC 3987 §2.2: IRI component allows UCSCHAR and (in query) iprivate in addition to RFC 3986 set.
     */
    private static function componentIsValidRfc3987(string $component): bool
    {
        return preg_match(self::RFC3987_COMPONENT_PATTERN, $component) === 1;
    }

    /**
     * Validates authority subcomponents (userinfo and host) per RFC 3986.
     */
    private function authorityPartsValidRfc3986(): bool
    {
        $parts = $this->getAuthorityParts();
        if ($parts === null) {
            return true;
        }

        $userinfo = $parts[0];
        $host     = $parts[1];

        if ($userinfo !== null && ! self::componentIsValidRfc3986($userinfo)) {
            return false;
        }

        return $host !== '' && self::componentIsValidRfc3986($host);
    }

    /**
     * Validates authority subcomponents (userinfo and host) per RFC 3987.
     */
    private function authorityPartsValidRfc3987(): bool
    {
        $parts = $this->getAuthorityParts();
        if ($parts === null) {
            return true;
        }

        $userinfo = $parts[0];
        $host     = $parts[1];

        if ($userinfo !== null && ! self::componentIsValidRfc3987($userinfo)) {
            return false;
        }

        return $host !== '' && self::componentIsValidRfc3987($host);
    }

    /** RFC 3986: unreserved | gen-delims | sub-delims | pct-encoded (ASCII only checked separately) */
    private const string RFC3986_COMPONENT_PATTERN = '/^(?:[A-Za-z0-9\-._~:\/\?#\[\]@!$&\'()*+,;=]|%[0-9A-Fa-f]{2})*$/';

    /** RFC 3987: RFC 3986 set plus ucschar and iprivate ranges (character classes for Unicode ranges) */
    private const string RFC3987_COMPONENT_PATTERN = '/^(?:[A-Za-z0-9\-._~:\/\?#\[\]@!$&\'()*+,;=]|%[0-9A-Fa-f]{2}|'
        . '[\x{A0}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFEF}'
        . '\x{10000}-\x{1FFFD}\x{20000}-\x{2FFFD}\x{30000}-\x{3FFFD}\x{40000}-\x{4FFFD}\x{50000}-\x{5FFFD}\x{60000}-\x{6FFFD}'
        . '\x{70000}-\x{7FFFD}\x{80000}-\x{8FFFD}\x{90000}-\x{9FFFD}'
        . '\x{A0000}-\x{AFFFD}\x{B0000}-\x{BFFFD}\x{C0000}-\x{CFFFD}\x{D0000}-\x{DFFFD}\x{E1000}-\x{EFFFD}'
        . '\x{E000}-\x{F8FF}\x{F0000}-\x{FFFFD}\x{100000}-\x{10FFFD}])*$/u';

    // ===========================
    // Authority parts (RFC 3986 §3.2: authority = [ userinfo "@" ] host [ ":" port ])
    // ===========================

    /**
     * User information from the authority component, if present.
     *
     * @see https://www.rfc-editor.org/rfc/rfc3986#section-3.2.1
     */
    public function getUserInfo(): string|null
    {
        $parts = $this->getAuthorityParts();
        if ($parts === null) {
            return null;
        }

        return $parts[0];
    }

    /**
     * Host from the authority component, if authority is present.
     *
     * @see https://www.rfc-editor.org/rfc/rfc3986#section-3.2.2
     */
    public function getHost(): string|null
    {
        $parts = $this->getAuthorityParts();
        if ($parts === null) {
            return null;
        }

        return $parts[1];
    }

    /**
     * Port from the authority component (without leading colon), if present.
     *
     * @see https://www.rfc-editor.org/rfc/rfc3986#section-3.2.3
     */
    public function getPort(): string|null
    {
        $parts = $this->getAuthorityParts();
        if ($parts === null) {
            return null;
        }

        return $parts[2];
    }

    /**
     * Port info from the authority component as an integer, if present.
     *
     * @return int|null
     *    Technically, RFC 3986 and 3987 do not limit the maximum port size, so it is not guaranteed that this fits into the int datatype.
     *    Use getPort() instead if you need the full port.
     */
    public function getPortInt(): int|null
    {
        $port = $this->getPort();
        if ($port === null || ! is_numeric($port)) {
            return null;
        }

        return (int) $port;
    }

    /**
     * Returns parsed authority as [userinfo, host, port] or null when authority is absent.
     *
     * @return array{0: string|null, 1: string, 2: string|null}|null
     *  [userinfo, host, port]
     */
    public function getAuthorityParts(): array|null
    {
        if ($this->authority === null) {
            return null;
        }

        $at       = strpos($this->authority, '@');
        $hostPort = $at !== false ? substr($this->authority, $at + 1) : $this->authority;

        $userinfo = $at !== false ? substr($this->authority, 0, $at) : null;

        if ($hostPort === '') {
            return [$userinfo, '', null];
        }

        if ($hostPort[0] === '[') {
            $close = strpos($hostPort, ']');
            if ($close === false) {
                $host = $hostPort;
                $port = null;
            } else {
                $host = substr($hostPort, 0, $close + 1);
                $rest = substr($hostPort, $close + 1);
                $port = str_starts_with($rest, ':') ? substr($rest, 1) : null;
            }
        } else {
            $colon = strpos($hostPort, ':');
            $host  = $colon !== false ? substr($hostPort, 0, $colon) : $hostPort;
            $port  = $colon !== false ? substr($hostPort, $colon + 1) : null;
        }

        return [$userinfo, $host, $port];
    }

    // ===========================
    // Different usages of a URI / IRI Reference
    // ===========================

    /**
     * Checks if this is a relative reference per RFC 3986 §4.2 or RFC 3987 §2.2.
     *
     * @see https://www.rfc-editor.org/rfc/rfc3986#section-4.2
     * @see https://www.rfc-editor.org/rfc/rfc3987#section-2.2
     *
     * @phpstan-assert-if-true null $this->scheme
     * @phpstan-assert-if-false !null $this->scheme
     */
    public function isRelativeReference(): bool
    {
        return $this->scheme === null;
    }

    /**
     * Checks if this is an absolute URI or IRI per RFC 3986 §4.3 or RFC 3987 §2.2.
     *
     * @see https://www.rfc-editor.org/rfc/rfc3986#section-4.3
     * @see https://www.rfc-editor.org/rfc/rfc3987#section-2.2
     *
     * @phpstan-assert-if-true =non-empty-string $this->scheme
     * @phpstan-assert-if-true =null $this->fragment
     */
    public function isAbsoluteURI(): bool
    {
        return $this->scheme !== null && $this->fragment === null;
    }

    /**
     * Checks if this is a same-document reference per RFC 3986 §4.4.
     *
     * A same-document reference refers to a URI that is, aside from its fragment
     * component (if any), identical to the base URI.
     *
     * @see https://www.rfc-editor.org/rfc/rfc3986#section-4.4
     */
    public function isSameDocumentReference(UriReference $base): bool
    {
        $resolved = $base->resolve($this);

        return $resolved->scheme === $base->scheme
            && $resolved->authority === $base->authority
            && $resolved->path === $base->path
            && $resolved->query === $base->query;
    }

    /**
     * Checks if this is a suffix reference per RFC 3986 §4.5.
     *
     * A suffix reference consists of only the authority and path portions of the URI,
     * such as "www.w3.org/Addressing/" or simply a DNS registered name.
     *
     * @see https://www.rfc-editor.org/rfc/rfc3986#section-4.5
     *
     * @phpstan-assert-if-true =null $this->scheme
     * @phpstan-assert-if-true =null $this->query
     * @phpstan-assert-if-true =null $this->fragment
     */
    public function isSuffixReference(): bool
    {
        return $this->scheme === null
            && $this->query === null
            && $this->fragment === null
            && ($this->authority !== null || $this->path !== '');
    }

    // ===========================
    // Resolving
    // ===========================

    /**
     * Resolves a URI reference string a base URI string according to RFC 3986.
     *
     * This method is a convenience wrapper around the parse() and resolve() methods.
     *
     * @see \FancyRDF\Uri\UriReference::parse()
     * @see \FancyRDF\Uri\UriReference::resolve()
     *
     * @return string The resolved URI. Whenever possible, this is an absolute URI.
     */
    public static function resolveRelative(string $base, string $uri, bool $strict = true, bool $normalize = true): string
    {
        // TODO: Introduce a cache here!
        return self::parse($base)->resolve(self::parse($uri))->toString();
    }

    /**
     * Resolves a URI reference against this URI (as a base URI) per RFC 3986 §5.
     *
     * @see https://www.rfc-editor.org/rfc/rfc3986#section-5
     *
     * @param bool $strict
     *   If true (the default) act as a strict parser
     * @param bool $normalize
     *   If true (the default) apply all normalization rules of the normalize() method prior to using the base URI.
     */
    public function resolve(UriReference $reference, bool $strict = true, bool $normalize = true): self
    {
        // Establish a base URI for resolving.
        //
        // Per [1] we do not need a base fragment is simply never used.
        //
        // [1] https://www.rfc-editor.org/rfc/rfc3986#section-5.1
        $baseScheme    = $this->scheme;
        $baseAuthority = $this->authority;
        $basePath      = $this->path;
        $baseQuery     = $this->query;

        // Normalization of the base URI, as described in Sections 6.2.2 and
        // 6.2.3, is optional.
        //
        // So only do so if the caller has requested it.
        if ($normalize) {
            self::normalizeComponents(
                $baseScheme,
                $baseAuthority,
                $basePath,
                $baseQuery,
                $baseFragment,
                true,
                true,
                true,
            );
        }

        // -- The URI reference is parsed into the five URI components
        $refScheme    = $reference->scheme;
        $refAuthority = $reference->authority;
        $refPath      = $reference->path;
        $refQuery     = $reference->query;
        $refFragment  = $reference->fragment;

        // A non-strict parser may ignore a scheme in the reference
        // if it is identical to the base URI's scheme.
        if (! $strict && $refScheme === $baseScheme) {
            $refScheme = null;
        }

        if ($refScheme !== null) {
            $targetScheme    = $refScheme;
            $targetAuthority = $refAuthority;
            $targetPath      = self::removeDotSegments($refPath);
            $targetQuery     = $refQuery;
        } else {
            if ($refAuthority !== null) {
                $targetAuthority = $refAuthority;
                $targetPath      = self::removeDotSegments($refPath);
                $targetQuery     = $refQuery;
            } else {
                if ($refPath === '') {
                    $targetPath = $basePath;
                    if ($refQuery !== null) {
                        $targetQuery = $refQuery;
                    } else {
                        $targetQuery = $baseQuery;
                    }
                } else {
                    if (str_starts_with($refPath, '/')) {
                        $targetPath = self::removeDotSegments($refPath);
                    } else {
                        $targetPath = $this->mergePath($basePath, $refPath);
                        $targetPath = self::removeDotSegments($targetPath);
                    }

                    $targetQuery = $refQuery;
                }

                $targetAuthority = $baseAuthority;
            }

            $targetScheme = $baseScheme;
        }

        $targetFragment = $refFragment;

        // TODO: If nothing has changed, do we want to return the URI itself here?
        return new self(
            $targetScheme,
            $targetAuthority,
            $targetPath,
            $targetQuery,
            $targetFragment,
        );
    }

    /**
     * Implements the Merge Routine from RFC 3986 §5.2.3.
     *
     * @see https://www.rfc-editor.org/rfc/rfc3986#section-5.2.3
     * Merges base path with reference path per RFC 3986 §5.2.3.
     */
    private function mergePath(string $basePath, string $refPath): string
    {
        // If the base URI has a defined authority component and an empty
        // path, then return a string consisting of "/" concatenated with the
        // reference's path
        if ($basePath === '' || $basePath === '/') {
            return '/' . $refPath;
        }

        // return a string consisting of the reference's path component
        // appended to all but the last segment of the base URI's path (i.e.,
        // excluding any characters after the right-most "/" in the base URI
        // path, or excluding the entire base URI path if it does not contain
        // any "/" characters).
        $lastSlash = strrpos($basePath, '/');
        if ($lastSlash === false) {
            return $refPath;
        }

        return substr($basePath, 0, $lastSlash + 1) . $refPath;
    }

    /**
     * Implements the Remove Dot Segment routine from RFC 3986 §5.2.4.
     *
     * @see https://www.rfc-editor.org/rfc/rfc3986#section-5.2.4
     */
    private static function removeDotSegments(string $path): string
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

    // ===========================
    // Normalization
    // ===========================

    /**
     * Applies syntax-based normalization rules to this URI reference as per RFC 3986 §6.2.2.
     *
     * @see https://www.rfc-editor.org/rfc/rfc3986#section-6.2.2
     * @see https://www.rfc-editor.org/rfc/rfc3986#section-6.2.2.1
     * @see https://www.rfc-editor.org/rfc/rfc3986#section-6.2.2.2
     * @see https://www.rfc-editor.org/rfc/rfc3986#section-6.2.2.3
     *
     * @param bool $case            If true, apply case normalization per RFC 3986 §6.2.2.1.
     * @param bool $percentEncoding If true, apply percent encoding normalization per RFC 3986 §6.2.2.2.
     * @param bool $pathSegment     If true, apply path segment normalization per RFC 3986 §6.2.2.3.
     *
     * @return self The normalized URI reference.
     */
    public function normalize(
        bool $case = true,
        bool $percentEncoding = true,
        bool $pathSegment = true,
    ): self {
        $scheme    = $this->scheme;
        $authority = $this->authority;
        $path      = $this->path;
        $query     = $this->query;
        $fragment  = $this->fragment;

        $changed = self::normalizeComponents(
            $scheme,
            $authority,
            $path,
            $query,
            $fragment,
            $case,
            $percentEncoding,
            $pathSegment,
        );
        if (! $changed) {
            return $this;
        }

        return new self($scheme, $authority, $path, $query, $fragment);
    }

    /**
     * Applies syntax-based normalization rules to the components of a URI reference as per RFC 3986 §6.2.2.
     *
     * @see \FancyRDF\Url\UriReference::normalize()
     *
     * @param non-empty-string|null &$scheme
     * @param non-empty-string|null &$authority
     * @param non-empty-string|null &$query
     * @param non-empty-string|null &$fragment
     * @param non-empty-string|null $fragment
     */
    private static function normalizeComponents(
        string|null &$scheme,
        string|null &$authority,
        string &$path,
        string|null &$query,
        string|null &$fragment,
        bool $case,
        bool $percentEncoding,
        bool $pathSegment,
    ): bool {
        $changed = false;
        if ($case) {
            $caseChanged = self::normalizeCase($scheme, $authority, $path, $query, $fragment);
            $changed     = $caseChanged;
        }

        if ($percentEncoding) {
            $percentEncodingChanged = self::normalizePercentEncodingInUriString($scheme, $authority, $path, $query, $fragment);
            $changed                = $changed || $percentEncodingChanged;
        }

        if ($pathSegment) {
            $newPath = self::removeDotSegments($path);
            $changed = $changed || $newPath !== $path;
            $path    = $newPath;
        }

        return $changed;
    }

    /**
     * Case normalization per RFC 3986 §6.2.2.1.
     * Normalizes percent-encoding hex to uppercase in path/query/fragment; scheme and host to lowercase.
     * Modifies the given component strings in place.
     *
     * @see https://www.rfc-editor.org/rfc/rfc3986#section-6.2.2.1
     *
     * @param non-empty-string|null $scheme
     * @param non-empty-string|null $authority
     * @param non-empty-string|null $query
     * @param non-empty-string|null $fragment
     */
    private static function normalizeCase(
        string|null &$scheme,
        string|null &$authority,
        string &$path,
        string|null &$query,
        string|null &$fragment,
    ): bool {
        $changed = false;

        if ($scheme !== null) {
            $newScheme = strtolower($scheme);
            $changed   = $newScheme !== $scheme;
            $scheme    = $newScheme;
        }

        if ($authority !== null) {
            $newAuthority = self::lowercaseHostInAuthority($authority);
            $changed      = $changed || $newAuthority !== $authority;
            $authority    = $newAuthority;
        }

        $newPath = self::uppercasePercentEncodingHex($path);
        $changed = $changed || $newPath !== $path;
        $path    = $newPath;

        if ($query !== null) {
            $newQuery = self::uppercasePercentEncodingHex($query);
            $changed  = $changed || $newQuery !== $query;
            $query    = $newQuery;
        }

        if ($fragment !== null) {
            $newFragment = self::uppercasePercentEncodingHex($fragment);
            $changed     = $changed || $newFragment !== $fragment;
            $fragment    = $newFragment;
        }

        return $changed;
    }

    /**
     * Percent-encoding normalization per RFC 3986 §6.2.2.2.
     * Decodes any percent-encoded octet that corresponds to an unreserved character (Section 2.3).
     * Modifies the given component strings in place.
     *
     * @see https://www.rfc-editor.org/rfc/rfc3986#section-6.2.2.2
     * @see https://www.rfc-editor.org/rfc/rfc3986#section-2.3
     *
     * @param non-empty-string|null $scheme
     * @param non-empty-string|null $authority
     * @param non-empty-string|null $query
     * @param non-empty-string|null $fragment
     */
    private static function normalizePercentEncodingInUriString(
        string|null &$scheme,
        string|null &$authority,
        string &$path,
        string|null &$query,
        string|null &$fragment,
    ): bool {
        $changed = false;

        if ($scheme !== null) {
            $newScheme = self::decodeUnreservedInComponent($scheme);
            $changed   = $newScheme !== $scheme;
            $scheme    = $newScheme;
        }

        if ($authority !== null) {
            $newAuthority = self::decodeUnreservedInComponent($authority);
            $changed      = $changed || $newAuthority !== $authority;
            $authority    = $newAuthority;
        }

        $newPath = self::decodeUnreservedInComponent($path);
        $changed = $changed || $newPath !== $path;
        $path    = $newPath;

        if ($query !== null) {
            $newQuery = self::decodeUnreservedInComponent($query);
            $changed  = $changed || $newQuery !== $query;
            $query    = $newQuery;
        }

        if ($fragment !== null) {
            $newFragment = self::decodeUnreservedInComponent($fragment);
            $changed     = $changed || $newFragment !== $fragment;
            $fragment    = $newFragment;
        }

        return $changed;
    }

    /**
     * Lowercases the host subcomponent within an authority string per RFC 3986 §6.2.2.1.
     * authority = [ userinfo "@" ] host [ ":" port ]; host is case-insensitive.
     *
     * @param non-empty-string $authority
     *
     * @return non-empty-string
     */
    private static function lowercaseHostInAuthority(string $authority): string
    {
        $at       = strpos($authority, '@');
        $hostPort = $at !== false ? substr($authority, $at + 1) : $authority;
        if ($hostPort === '') {
            return $authority;
        }

        if ($hostPort[0] === '[') {
            $close = strpos($hostPort, ']');
            if ($close === false) {
                return $authority;
            }

            $host = substr($hostPort, 0, $close + 1);
            $port = substr($hostPort, $close + 1);
        } else {
            $colon = strpos($hostPort, ':');
            $host  = $colon !== false ? substr($hostPort, 0, $colon) : $hostPort;
            $port  = $colon !== false ? substr($hostPort, $colon) : '';
        }

        $hostLower = strtolower($host);
        if ($at !== false) {
            return substr($authority, 0, $at + 1) . $hostLower . $port;
        }

        $result = $hostLower . $port;
        assert($result !== '');

        return $result;
    }

    /**
     * Uppercases hexadecimal digits in percent-encoded triplets (e.g. %3a → %3A).
     *
     * @return ($s is non-empty-string ? non-empty-string : string)
     */
    private static function uppercasePercentEncodingHex(string $s): string
    {
        $result = preg_replace_callback('/%([0-9A-Fa-f])([0-9A-Fa-f])/', static function (array $m): string {
            return '%' . strtoupper($m[1]) . strtoupper($m[2]);
        }, $s);
        assert($result !== null && ($s === '' || $result !== ''));

        return $result;
    }

    /**
     * Decodes percent-encoded octets that correspond to unreserved characters (ALPHA, DIGIT, -, ., _, ~) in a single component.
     *
     * @return ($s is non-empty-string ? non-empty-string : string)
     */
    private static function decodeUnreservedInComponent(string $s): string
    {
        $result = preg_replace_callback('/%([0-9A-Fa-f]{2})/', static function (array $m): string {
            $octet = (int) hexdec($m[1]);
            if ($octet >= 0x30 && $octet <= 0x39) {
                return chr($octet);
            }

            if ($octet >= 0x41 && $octet <= 0x5A) {
                return chr($octet);
            }

            if ($octet >= 0x61 && $octet <= 0x7A) {
                return chr($octet);
            }

            if ($octet === 0x2D || $octet === 0x2E || $octet === 0x5F || $octet === 0x7E) {
                return chr($octet);
            }

            return '%' . strtoupper($m[1]);
        }, $s);
        if ($result === null) {
            throw new RuntimeException('Failed to decode unreserved in component: ' . $s);
        }

        return $result;
    }

    // ===========================
    // Parsing & Recomposition
    // ===========================

    /**
     * Parses a URI reference string into it's syntactical components as described in section 3 of RFC 3986.
     *
     * @see https://www.rfc-editor.org/rfc/rfc3986#section-3
     */
    public static function parse(string $uri): self
    {
        // This code uses the reference implementation by means of the regular expression from Appendix B of [1]:
        //
        // ^(([^:/?#]+):)?(//([^/?#]*))?([^?#]*)(\?([^#]*))?(#(.*))?
        //
        // This allows matching the components of the URI reference as follows:
        //
        //       scheme    = $2
        //       authority = $4
        //       path      = $5
        //       query     = $7
        //       fragment  = $9
        //
        // [1] https://www.rfc-editor.org/rfc/rfc3986#appendix-B

        // We use ~ as a delimiter for the regexp so we don't have to do more escaping!
        if (! preg_match('~^(([^:/?#]+):)?(//([^/?#]*))?([^?#]*)(\?([^#]*))?(#(.*))?$~s', $uri, $matches)) {
            return new self(null, null, $uri, null, null);
        }

        $scheme    = $matches[2];
        $authority = $matches[4];
        $path      = $matches[5];
        $query     = $matches[7] ?? '';
        $fragment  = $matches[9] ?? '';

        return new self(
            $scheme !== '' ? $scheme : null,
            $authority !== '' ? $authority : null,
            $path,
            $query !== '' ? $query : null,
            $fragment !== '' ? $fragment : null,
        );
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
}
