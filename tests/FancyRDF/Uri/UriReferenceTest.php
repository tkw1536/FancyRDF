<?php

declare(strict_types=1);

namespace FancyRDF\Tests\FancyRDF\Uri;

use FancyRDF\Uri\UriReference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class UriReferenceTest extends TestCase
{
    /**
     * Examples from RFC 3986.
     *
     * @return array<string, array{string, UriReference, bool, bool, bool}>
     *     Array of [uriString, uri, isRelativeReference, isAbsoluteURI, isSuffixReference]
     */
    public static function rfc3986SpecParseProvider(): array
    {
        $examples = [
            // RFC 3986 §1.1.2 Examples
            'ftp://ftp.is.co.za/rfc/rfc1808.txt' => [
                new UriReference('ftp', 'ftp.is.co.za', '/rfc/rfc1808.txt', null, null),
                false, // isRelativeReference
                true,  // isAbsoluteURI
                false, // isSuffixReference
            ],
            'http://www.ietf.org/rfc/rfc2396.txt' => [
                new UriReference('http', 'www.ietf.org', '/rfc/rfc2396.txt', null, null),
                false,
                true,
                false,
            ],
            'ldap://[2001:db8::7]/c=GB?objectClass?one' => [
                new UriReference('ldap', '[2001:db8::7]', '/c=GB', 'objectClass?one', null),
                false,
                true,
                false,
            ],
            'mailto:John.Doe@example.com' => [
                new UriReference('mailto', null, 'John.Doe@example.com', null, null),
                false,
                true,
                false,
            ],
            'news:comp.infosystems.www.servers.unix' => [
                new UriReference('news', null, 'comp.infosystems.www.servers.unix', null, null),
                false,
                true,
                false,
            ],
            'tel:+1-816-555-1212' => [
                new UriReference('tel', null, '+1-816-555-1212', null, null),
                false,
                true,
                false,
            ],
            'telnet://192.0.2.16:80/' => [
                new UriReference('telnet', '192.0.2.16:80', '/', null, null),
                false,
                true,
                false,
            ],
            'urn:oasis:names:specification:docbook:dtd:xml:4.1.2' => [
                new UriReference('urn', null, 'oasis:names:specification:docbook:dtd:xml:4.1.2', null, null),
                false,
                true,
                false,
            ],

            // RFC 3986 §5.4 base and components
            'http://a/b/c/d;p?q' => [
                new UriReference('http', 'a', '/b/c/d;p', 'q', null),
                false,
                true,
                false,
            ],
            'http://a/b/c/d;p?q#s' => [
                new UriReference('http', 'a', '/b/c/d;p', 'q', 's'),
                false,
                false,
                false,
            ],
            'http://a/b/c/g/' => [
                new UriReference('http', 'a', '/b/c/g/', null, null),
                false,
                true,
                false,
            ],
            'http://g' => [
                new UriReference('http', 'g', '', null, null),
                false,
                true,
                false,
            ],
            'http://a/b/c/g?y/./x' => [
                new UriReference('http', 'a', '/b/c/g', 'y/./x', null),
                false,
                true,
                false,
            ],
            'http://a/b/c/g#s/../x' => [
                new UriReference('http', 'a', '/b/c/g', null, 's/../x'),
                false,
                false,
                false,
            ],

            // Relative references: path-only, query-only, fragment-only
            'g' => [
                new UriReference(null, null, 'g', null, null),
                true,
                false,
                true, // suffix: no scheme/query/fragment, path non-empty
            ],
            'g/' => [
                new UriReference(null, null, 'g/', null, null),
                true,
                false,
                true,
            ],
            './g' => [
                new UriReference(null, null, './g', null, null),
                true,
                false,
                true,
            ],
            '/g' => [
                new UriReference(null, null, '/g', null, null),
                true,
                false,
                true,
            ],
            '?y' => [
                new UriReference(null, null, '', 'y', null),
                true,
                false,
                false, // has query
            ],
            '#s' => [
                new UriReference(null, null, '', null, 's'),
                true,
                false,
                false, // has fragment
            ],
            'g?y' => [
                new UriReference(null, null, 'g', 'y', null),
                true,
                false,
                false, // has query
            ],
            'g#s' => [
                new UriReference(null, null, 'g', null, 's'),
                true,
                false,
                false, // has fragment
            ],
            'g?y#s' => [
                new UriReference(null, null, 'g', 'y', 's'),
                true,
                false,
                false, // has query and fragment
            ],

            // Network path reference
            '//example.com/path' => [
                new UriReference(null, 'example.com', '/path', null, null),
                true,
                false,
                true, // suffix: authority and path, no query/fragment
            ],
            '//g' => [
                new UriReference(null, 'g', '', null, null),
                true,
                false,
                true, // suffix: authority only
            ],

            // Empty
            '' => [
                new UriReference(null, null, '', null, null),
                true,
                false,
                false, // path empty and no authority
            ],
        ];

        $cases = [];
        foreach ($examples as $uri => $data) {
            [$expected, $isRelativeReference, $isAbsoluteURI, $isSuffixReference] = $data;
            $cases[$uri]                                                          = [
                $uri,
                $expected,
                $isRelativeReference,
                $isAbsoluteURI,
                $isSuffixReference,
            ];
        }

        return $cases;
    }

    /**
     * Tests isRelativeReference() per RFC 3986 §4.2.
     */
    #[DataProvider('rfc3986SpecParseProvider')]
    #[TestDox('Is relative reference for $uriString')]
    public function testIsRelativeReference(string $uriString, UriReference $uri, bool $expectedIsRelative, bool $expectedIsAbsolute, bool $expectedIsSuffix): void
    {
        self::assertSame($expectedIsRelative, $uri->isRelativeReference());
    }

    /**
     * Tests isAbsoluteURI() per RFC 3986 §4.3.
     */
    #[DataProvider('rfc3986SpecParseProvider')]
    #[TestDox('Is absolute URI for $uriString')]
    public function testIsAbsoluteURI(string $uriString, UriReference $uri, bool $expectedIsRelative, bool $expectedIsAbsolute, bool $expectedIsSuffix): void
    {
        self::assertSame($expectedIsAbsolute, $uri->isAbsoluteURI());
    }

    /**
     * Tests isSuffixReference() per RFC 3986 §4.5.
     */
    #[DataProvider('rfc3986SpecParseProvider')]
    #[TestDox('Is suffix reference for $uriString')]
    public function testIsSuffixReference(string $uriString, UriReference $uri, bool $expectedIsRelative, bool $expectedIsAbsolute, bool $expectedIsSuffix): void
    {
        self::assertSame($expectedIsSuffix, $uri->isSuffixReference());
    }

    /**
     * Examples for testing isSameDocumentReference() per RFC 3986 §4.4.
     *
     * @return array<string, array{string, string, bool}>
     *     Array of [baseUri, referenceUri, isSameDocument]
     */
    public static function sameDocumentReferenceProvider(): array
    {
        $base = 'http://a/b/c/d;p?q';

        $examples = [
            // Same document references (only fragment differs)
            'empty reference' => [
                $base,
                '',
                true,
            ],
            'fragment-only' => [
                $base,
                '#s',
                true,
            ],
            'fragment-only different' => [
                $base,
                '#other',
                true,
            ],
            // Different document references
            'query-only' => [
                $base,
                '?y',
                false, // query differs
            ],
            'path-only relative' => [
                $base,
                'g',
                false, // resolves to http://a/b/c/g, path differs
            ],
            'path-only absolute' => [
                $base,
                '/g',
                false, // resolves to http://a/g, path differs
            ],
            'network path' => [
                $base,
                '//example.com/path',
                false, // authority differs
            ],
            'absolute URI same' => [
                $base,
                'http://a/b/c/d;p?q',
                true, // identical
            ],
            'absolute URI same with fragment' => [
                $base,
                'http://a/b/c/d;p?q#frag',
                true, // same except fragment
            ],
            'absolute URI different query' => [
                $base,
                'http://a/b/c/d;p?r',
                false, // query differs
            ],
            'absolute URI different path' => [
                $base,
                'http://a/b/c/g',
                false, // path differs
            ],
            'absolute URI different authority' => [
                $base,
                'http://b/b/c/d;p?q',
                false, // authority differs
            ],
            'absolute URI different scheme' => [
                $base,
                'https://a/b/c/d;p?q',
                false, // scheme differs
            ],
            // Edge cases
            'base without query, reference with query' => [
                'http://a/b/c/d;p',
                '?q',
                false, // query differs
            ],
            'base with query, reference without query' => [
                'http://a/b/c/d;p?q',
                'http://a/b/c/d;p',
                false, // query differs
            ],
            'base without path' => [
                'http://a',
                '',
                true, // same document
            ],
            'base without path, fragment' => [
                'http://a',
                '#frag',
                true, // same document
            ],
            'base without path, different path' => [
                'http://a',
                '/b',
                false, // path differs
            ],
        ];

        $cases = [];
        foreach ($examples as $name => $data) {
            [$baseUri, $referenceUri, $isSameDocument] = $data;
            $cases[$name]                              = [
                $baseUri,
                $referenceUri,
                $isSameDocument,
            ];
        }

        return $cases;
    }

    /**
     * Tests isSameDocumentReference() per RFC 3986 §4.4.
     */
    #[DataProvider('sameDocumentReferenceProvider')]
    #[TestDox('Is same document reference: base=$baseUri, reference=$referenceUri')]
    public function testIsSameDocumentReference(string $baseUri, string $referenceUri, bool $expectedIsSameDocument): void
    {
        $base      = UriReference::parse($baseUri);
        $reference = UriReference::parse($referenceUri);

        self::assertSame($expectedIsSameDocument, $reference->isSameDocumentReference($base));
    }

    /** @return array<string, array{string, string, string}> */
    public static function rfc3986SpecResolveProvider(): array
    {
        $cases = [
           // 5.4.1 Normal Examples
            'g:h'           =>  'g:h',
            'g'             =>  'http://a/b/c/g',
            './g'           =>  'http://a/b/c/g',
            'g/'            =>  'http://a/b/c/g/',
            '/g'            =>  'http://a/g',
            '//g'           =>  'http://g',
            '?y'            =>  'http://a/b/c/d;p?y',
            'g?y'           =>  'http://a/b/c/g?y',
            '#s'            =>  'http://a/b/c/d;p?q#s',
            'g#s'           =>  'http://a/b/c/g#s',
            'g?y#s'         =>  'http://a/b/c/g?y#s',
            ';x'            =>  'http://a/b/c/;x',
            'g;x'           =>  'http://a/b/c/g;x',
            'g;x?y#s'       =>  'http://a/b/c/g;x?y#s',
            ''              =>  'http://a/b/c/d;p?q',
            '.'             =>  'http://a/b/c/',
            './'            =>  'http://a/b/c/',
            '..'            =>  'http://a/b/',
            '../'           =>  'http://a/b/',
            '../g'          =>  'http://a/b/g',
            '../..'         =>  'http://a/',
            '../../'        =>  'http://a/',
            '../../g'       =>  'http://a/g',

           // 5.4.2 Abnormal Examples
            '../../../g'    =>  'http://a/g',
            '../../../../g' =>  'http://a/g',

            '/./g'          =>  'http://a/g',
            '/../g'         =>  'http://a/g',
            'g.'            =>  'http://a/b/c/g.',
            '.g'            =>  'http://a/b/c/.g',
            'g..'           =>  'http://a/b/c/g..',
            '..g'           =>  'http://a/b/c/..g',

            './../g'        =>  'http://a/b/g',
            './g/.'         =>  'http://a/b/c/g/',
            'g/./h'         =>  'http://a/b/c/g/h',
            'g/../h'        =>  'http://a/b/c/h',
            'g;x=1/./y'     =>  'http://a/b/c/g;x=1/y',
            'g;x=1/../y'    =>  'http://a/b/c/y',

            'g?y/./x'       =>  'http://a/b/c/g?y/./x',
            'g?y/../x'      =>  'http://a/b/c/g?y/../x',
            'g#s/./x'       =>  'http://a/b/c/g#s/./x',
            'g#s/../x'      =>  'http://a/b/c/g#s/../x',
        ];

        $examples = [];
        foreach ($cases as $relative => $cases) {
            $examples['RFC 3986 example ' . $relative] = [
                'http://a/b/c/d;p?q',
                $relative,
                $cases,
            ];
        }

        return $examples;
    }

    /** @return array<string, array{string, string, string}> */
    public static function customUrlResolveProvider(): array
    {
        return [
            'empty relative returns base without fragment' => [
                'http://example.org/dir/file#frag',
                '',
                'http://example.org/dir/file',
            ],
            'network path uses base scheme' => [
                'http://example.org/dir/file',
                '//another.example.org/absfile',
                'http://another.example.org/absfile',
            ],
            'absolute URI returned as-is' => [
                'http://example.org/dir/file',
                'https://other.example.org/path',
                'https://other.example.org/path',
            ],
            'fragment-only appends to base without fragment' => [
                'http://example.org/dir/file#old',
                '#new',
                'http://example.org/dir/file#new',
            ],
            'absolute path replaces path' => [
                'http://example.org/dir/file',
                '/newpath',
                'http://example.org/newpath',
            ],
            'relative path resolves against base directory' => [
                'http://example.org/dir/file',
                'relfile',
                'http://example.org/dir/relfile',
            ],
            'relative path with parent directory' => [
                'http://example.org/dir/file',
                '../relfile',
                'http://example.org/relfile',
            ],
            'base without path' => [
                'http://example.org',
                'relfile',
                'http://example.org/relfile',
            ],
            'base with trailing slash' => [
                'http://example.org/dir/',
                'relfile',
                'http://example.org/dir/relfile',
            ],
        ];
    }

    /**
     * Data provider for case normalization (RFC 3986 §6.2.2.1): input URI => expected output.
     * Only case normalization is applied (scheme/host lowercase, percent-encoding hex uppercase).
     *
     * @return array<string, array{string, string}>
     */
    public static function normalizeCaseProvider(): array
    {
        return [
            'scheme lowercased' => [
                'HTTP://example.com/',
                'http://example.com/',
            ],
            'host lowercased' => [
                'http://Example.COM/path',
                'http://example.com/path',
            ],
            'scheme and host lowercased' => [
                'HTTPS://WWW.IETF.ORG/rfc/rfc2396.txt',
                'https://www.ietf.org/rfc/rfc2396.txt',
            ],
            'percent-encoding hex uppercased' => [
                'http://example.com/%3apath',
                'http://example.com/%3Apath',
            ],
            'authority host lowercased with userinfo' => [
                'http://user@Host.EXAMPLE.com:80/',
                'http://user@host.example.com:80/',
            ],
            'IPv6 host lowercased' => [
                'http://[2001:DB8::7]/c=GB',
                'http://[2001:db8::7]/c=GB',
            ],
            'fragment percent-encoding hex uppercased' => [
                'http://example.com/#%7b',
                'http://example.com/#%7B',
            ],
        ];
    }

    /**
     * Data provider for percent-encoding normalization (RFC 3986 §6.2.2.2): input URI => expected output.
     * Only percent-encoding normalization is applied (decode unreserved characters).
     *
     * @return array<string, array{string, string}>
     */
    public static function normalizePercentEncodingProvider(): array
    {
        return [
            'decode percent-encoded letter' => [
                'http://example.com/%61bc',
                'http://example.com/abc',
            ],
            'decode percent-encoded tilde' => [
                'http://example.com/foo%7Ebar',
                'http://example.com/foo~bar',
            ],
            'decode percent-encoded period in path' => [
                'http://example.com/foo%2Ebar',
                'http://example.com/foo.bar',
            ],
            'decode percent-encoded hyphen and underscore' => [
                'http://example.com/%2d%5f%2d',
                'http://example.com/-_-',
            ],
            'decode percent-encoded digit' => [
                'http://example.com/%31%32%33',
                'http://example.com/123',
            ],
            'reserved character not decoded' => [
                'http://example.com/%2Fpath',
                'http://example.com/%2Fpath',
            ],
            'decode in query' => [
                'http://example.com?%71=value',
                'http://example.com?q=value',
            ],
            'decode in fragment' => [
                'http://example.com#%73ection',
                'http://example.com#section',
            ],
        ];
    }

    /**
     * Data provider for path segment normalization (RFC 3986 §6.2.2.3): input URI => expected output.
     * Only path segment normalization is applied (remove_dot_segments).
     *
     * @return array<string, array{string, string}>
     */
    public static function normalizePathSegmentProvider(): array
    {
        return [
            'remove . segment' => [
                'http://a/b/c/./../../g',
                'http://a/g',
            ],
            'remove . and .. segments' => [
                'http://a/./b/../b/c',
                'http://a/b/c',
            ],
            'leading ./ in path' => [
                'http://example.com/./path',
                'http://example.com/path',
            ],
            'leading ../ in path' => [
                'http://example.com/../path',
                'http://example.com/path',
            ],
            'single . segment' => [
                'http://example.com/.',
                'http://example.com/',
            ],
            'trailing /.' => [
                'http://example.com/./',
                'http://example.com/',
            ],
            'path /./g' => [
                'http://a/./g',
                'http://a/g',
            ],
            'path /../g' => [
                'http://a/../g',
                'http://a/g',
            ],
            'relative path with dot segments' => [
                'g/./h',
                'g/h',
            ],
        ];
    }

    /**
     * Case normalization per RFC 3986 §6.2.2.1.
     */
    #[DataProvider('normalizeCaseProvider')]
    #[TestDox('Normalize case: $input => $expected')]
    public function testNormalizeCase(string $input, string $expected): void
    {
        $ref        = UriReference::parse($input);
        $normalized = $ref->normalize(case: true, percentEncoding: false, pathSegment: false);
        self::assertSame($expected, $normalized->toString());
    }

    /**
     * Percent-encoding normalization per RFC 3986 §6.2.2.2.
     */
    #[DataProvider('normalizePercentEncodingProvider')]
    #[TestDox('Normalize percent-encoding: $input => $expected')]
    public function testNormalizePercentEncoding(string $input, string $expected): void
    {
        $ref        = UriReference::parse($input);
        $normalized = $ref->normalize(case: false, percentEncoding: true, pathSegment: false);
        self::assertSame($expected, $normalized->toString());
    }

    /**
     * Path segment normalization per RFC 3986 §6.2.2.3.
     */
    #[DataProvider('normalizePathSegmentProvider')]
    #[TestDox('Normalize path segment: $input => $expected')]
    public function testNormalizePathSegment(string $input, string $expected): void
    {
        $ref        = UriReference::parse($input);
        $normalized = $ref->normalize(case: false, percentEncoding: false, pathSegment: true);
        self::assertSame($expected, $normalized->toString());
    }

    /**
     * fromString() parses into scheme, authority, path, query, fragment per RFC 3986 §3.
     */
    #[DataProvider('rfc3986SpecParseProvider')]
    #[TestDox('Parse parses components for $uri')]
    public function testParseParsesComponents(string $uri, UriReference $expected): void
    {
        $parsed = UriReference::parse($uri);
        self::assertSame($expected->scheme, $parsed->scheme);
        self::assertSame($expected->authority, $parsed->authority);
        self::assertSame($expected->path, $parsed->path);
        self::assertSame($expected->query, $parsed->query);
        self::assertSame($expected->fragment, $parsed->fragment);
    }

    #[DataProvider('rfc3986SpecParseProvider')]
    #[TestDox('To string for $uri')]
    public function testToString(string $uri, UriReference $expected): void
    {
        self::assertSame($uri, $expected->toString());
    }

    #[DataProvider('rfc3986SpecParseProvider')]
    #[TestDox('Parse round trip for $uri')]
    public function testParseRoundTrip(string $uri, UriReference $expected): void
    {
        $parsed = UriReference::parse($uri);
        self::assertSame($uri, $parsed->toString());
    }

    #[DataProvider('customUrlResolveProvider')]
    #[DataProvider('rfc3986SpecResolveProvider')]
    #[TestDox('Resolve URLs: base=$base, relative=$relative, expected=$expected')]
    public function testResolveURLs(string $base, string $relative, string $expected): void
    {
        $result = UriReference::resolveURI($base, $relative);
        self::assertSame($expected, $result);
    }
}
