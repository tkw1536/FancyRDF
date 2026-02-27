<?php

declare(strict_types=1);

namespace FancyRDF\Tests\FancyRDF\Uri;

use FancyRDF\Uri\UriReference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class UriReferenceTest extends TestCase
{
    /**
     * Examples from RFC 3986.
     *
     * @return array<string, array{string, UriReference, bool, bool, bool, bool, bool}>
     *     Array of [uriString, uri, isRelativeReference, isAbsoluteURI, isSuffixReference, isRFC3986UriReference, isRFC3987IriReference]
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
                true,  // isRFC3986UriReference
                true,  // isRFC3987IriReference
            ],
            'http://www.ietf.org/rfc/rfc2396.txt' => [
                new UriReference('http', 'www.ietf.org', '/rfc/rfc2396.txt', null, null),
                false,
                true,
                false,
                true,
                true,
            ],
            'ldap://[2001:db8::7]/c=GB?objectClass?one' => [
                new UriReference('ldap', '[2001:db8::7]', '/c=GB', 'objectClass?one', null),
                false,
                true,
                false,
                true,
                true,
            ],
            'mailto:John.Doe@example.com' => [
                new UriReference('mailto', null, 'John.Doe@example.com', null, null),
                false,
                true,
                false,
                true,
                true,
            ],
            'news:comp.infosystems.www.servers.unix' => [
                new UriReference('news', null, 'comp.infosystems.www.servers.unix', null, null),
                false,
                true,
                false,
                true,
                true,
            ],
            'tel:+1-816-555-1212' => [
                new UriReference('tel', null, '+1-816-555-1212', null, null),
                false,
                true,
                false,
                true,
                true,
            ],
            'telnet://192.0.2.16:80/' => [
                new UriReference('telnet', '192.0.2.16:80', '/', null, null),
                false,
                true,
                false,
                true,
                true,
            ],
            'urn:oasis:names:specification:docbook:dtd:xml:4.1.2' => [
                new UriReference('urn', null, 'oasis:names:specification:docbook:dtd:xml:4.1.2', null, null),
                false,
                true,
                false,
                true,
                true,
            ],

            // RFC 3986 §5.4 base and components
            'http://a/b/c/d;p?q' => [
                new UriReference('http', 'a', '/b/c/d;p', 'q', null),
                false,
                true,
                false,
                true,
                true,
            ],
            'http://a/b/c/d;p?q#s' => [
                new UriReference('http', 'a', '/b/c/d;p', 'q', 's'),
                false,
                false,
                false,
                true,
                true,
            ],
            'http://a/b/c/g/' => [
                new UriReference('http', 'a', '/b/c/g/', null, null),
                false,
                true,
                false,
                true,
                true,
            ],
            'http://g' => [
                new UriReference('http', 'g', '', null, null),
                false,
                true,
                false,
                true,
                true,
            ],
            'http://a/b/c/g?y/./x' => [
                new UriReference('http', 'a', '/b/c/g', 'y/./x', null),
                false,
                true,
                false,
                true,
                true,
            ],
            'http://a/b/c/g#s/../x' => [
                new UriReference('http', 'a', '/b/c/g', null, 's/../x'),
                false,
                false,
                false,
                true,
                true,
            ],

            // RFC 3986: Path Examples from §3.3
            'mailto:fred@example.com' => [
                new UriReference('mailto', null, 'fred@example.com', null, null),
                false,
                true,
                false,
                true,
                true,
            ],

            'foo://info.example.com?fred' => [
                new UriReference('foo', 'info.example.com', '', 'fred', null),
                false,
                true,
                false,
                true,
                true,
            ],

            // IRI with ucschar in path (W3C localName_with_non_leading_extras: · U+00B7, ̀ U+0300, ͯ U+036F, ‿ U+203F, ⁀ U+2040)
            'http://a.example/a·̀ͯ‿.⁀' => [
                new UriReference('http', 'a.example', '/a·̀ͯ‿.⁀', null, null),
                false,
                true,
                false,
                false, // isRFC3986UriReference (path contains non-ASCII)
                true,  // isRFC3987IriReference
            ],

            // Relative references: path-only, query-only, fragment-only
            'g' => [
                new UriReference(null, null, 'g', null, null),
                true,
                false,
                true, // suffix: no scheme/query/fragment, path non-empty
                true,
                true,
            ],
            'g/' => [
                new UriReference(null, null, 'g/', null, null),
                true,
                false,
                true,
                true,
                true,
            ],
            './g' => [
                new UriReference(null, null, './g', null, null),
                true,
                false,
                true,
                true,
                true,
            ],
            '/g' => [
                new UriReference(null, null, '/g', null, null),
                true,
                false,
                true,
                true,
                true,
            ],
            '?y' => [
                new UriReference(null, null, '', 'y', null),
                true,
                false,
                false, // has query
                true,
                true,
            ],
            '#s' => [
                new UriReference(null, null, '', null, 's'),
                true,
                false,
                false, // has fragment
                true,
                true,
            ],
            'g?y' => [
                new UriReference(null, null, 'g', 'y', null),
                true,
                false,
                false, // has query
                true,
                true,
            ],
            'g#s' => [
                new UriReference(null, null, 'g', null, 's'),
                true,
                false,
                false, // has fragment
                true,
                true,
            ],
            'g?y#s' => [
                new UriReference(null, null, 'g', 'y', 's'),
                true,
                false,
                false, // has query and fragment
                true,
                true,
            ],

            // Network path reference
            '//example.com/path' => [
                new UriReference(null, 'example.com', '/path', null, null),
                true,
                false,
                true, // suffix: authority and path, no query/fragment
                true,
                true,
            ],
            '//g' => [
                new UriReference(null, 'g', '', null, null),
                true,
                false,
                true, // suffix: authority only
                true,
                true,
            ],

            // Empty
            '' => [
                new UriReference(null, null, '', null, null),
                true,
                false,
                false, // path empty and no authority
                true,
                true,
            ],

            // RFC 3986 vs RFC 3987: Unicode in path
            'http://example.com/café' => [
                new UriReference('http', 'example.com', '/café', null, null),
                false,
                true,
                false,
                false, // not valid RFC 3986 (non-ASCII)
                true,  // valid RFC 3987 IRI
            ],

            // === Custom Examples ===
            // Invalid: space not allowed in URI or IRI
            'http://example.com/foo bar' => [
                new UriReference('http', 'example.com', '/foo bar', null, null),
                false,
                true,
                false,
                false,
                false,
            ],
            '?' => [
                new UriReference(null, null, '', '', null),
                true, // isRelativeReference
                false,  // isAbsoluteURI
                false, // isSuffixReference
                true,  // isRFC3986UriReference
                true,  // isRFC3987IriReference
            ],
            'file:///a/bb/ccc/g' => [
                new UriReference('file', '', '/a/bb/ccc/g', null, null),
                false,
                true,
                false,
                true,
                true,
            ],
        ];

        $cases = [];
        foreach ($examples as $uri => $data) {
            [$expected, $isRelativeReference, $isAbsoluteURI, $isSuffixReference, $isRFC3986, $isRFC3987] = $data;
            $cases[$uri]                                                                                  = [
                $uri,
                $expected,
                $isRelativeReference,
                $isAbsoluteURI,
                $isSuffixReference,
                $isRFC3986,
                $isRFC3987,
            ];
        }

        return $cases;
    }

    /**
     * Tests isRelativeReference() per RFC 3986 §4.2.
     */
    #[DataProvider('rfc3986SpecParseProvider')]
    #[TestDox('Is relative reference for $uriString')]
    public function testIsRelativeReference(string $uriString, UriReference $uri, bool $expectedIsRelative, bool $_expectedIsAbsolute, bool $_expectedIsSuffix, bool $_expectedIsRfc3986, bool $_expectedIsRfc3987): void
    {
        self::assertSame($expectedIsRelative, $uri->isRelativeReference());
    }

    /**
     * Tests isAbsoluteURI() per RFC 3986 §4.3.
     */
    #[DataProvider('rfc3986SpecParseProvider')]
    #[TestDox('Is absolute URI for $uriString')]
    public function testIsAbsoluteURI(string $uriString, UriReference $uri, bool $_expectedIsRelative, bool $expectedIsAbsolute, bool $_expectedIsSuffix, bool $_expectedIsRfc3986, bool $_expectedIsRfc3987): void
    {
        self::assertSame($expectedIsAbsolute, $uri->isAbsoluteURI());
    }

    /**
     * Tests isSuffixReference() per RFC 3986 §4.5.
     */
    #[DataProvider('rfc3986SpecParseProvider')]
    #[TestDox('Is suffix reference for $uriString')]
    public function testIsSuffixReference(string $uriString, UriReference $uri, bool $_expectedIsRelative, bool $_expectedIsAbsolute, bool $expectedIsSuffix, bool $_expectedIsRfc3986, bool $_expectedIsRfc3987): void
    {
        self::assertSame($expectedIsSuffix, $uri->isSuffixReference());
    }

    /**
     * Tests isRFC3986UriReference() per RFC 3986 §2.
     */
    #[DataProvider('rfc3986SpecParseProvider')]
    #[TestDox('Is RFC 3986 URI reference for $uriString')]
    public function testIsRFC3986UriReference(string $uriString, UriReference $uri, bool $_expectedIsRelative, bool $_expectedIsAbsolute, bool $_expectedIsSuffix, bool $expectedIsRfc3986, bool $_expectedIsRfc3987): void
    {
        self::assertSame($expectedIsRfc3986, $uri->isRFC3986UriReference());
    }

    /**
     * Tests isRFC3987IriReference() per RFC 3987 §2.2.
     */
    #[DataProvider('rfc3986SpecParseProvider')]
    #[TestDox('Is RFC 3987 IRI reference for $uriString')]
    public function testIsRFC3987IriReference(string $uriString, UriReference $uri, bool $_expectedIsRelative, bool $_expectedIsAbsolute, bool $_expectedIsSuffix, bool $_expectedIsRfc3986, bool $expectedIsRfc3987): void
    {
        self::assertSame($expectedIsRfc3987, $uri->isRFC3987IriReference());
    }

    /**
     * Authority part accessors (getAuthorityUserInfo, getHost, getPort).
     *
     * @return array<string, array{UriReference, string|null, string|null, string|null}>
     */
    public static function authorityPartsProvider(): array
    {
        return [
            'no authority' => [
                new UriReference('http', null, '/path', null, null),
                null,
                null,
                null,
            ],
            'host only' => [
                new UriReference('http', 'example.com', '/', null, null),
                null,
                'example.com',
                null,
            ],
            'userinfo and host' => [
                new UriReference('http', 'user:pass@example.com', '/', null, null),
                'user:pass',
                'example.com',
                null,
            ],
            'host and port' => [
                new UriReference('telnet', '192.0.2.16:80', '/', null, null),
                null,
                '192.0.2.16',
                '80',
            ],
            'IP literal' => [
                new UriReference('ldap', '[2001:db8::7]', '/c=GB', null, null),
                null,
                '[2001:db8::7]',
                null,
            ],
            'userinfo, host and port' => [
                new UriReference('http', 'user@example.com:8080', '/', null, null),
                'user',
                'example.com',
                '8080',
            ],
        ];
    }

    #[DataProvider('authorityPartsProvider')]
    #[TestDox('Authority parts: userinfo, host and port')]
    public function testAuthorityPartsAccessors(UriReference $uri, string|null $expectedUserinfo, string|null $expectedHost, string|null $expectedPort): void
    {
        self::assertSame($expectedUserinfo, $uri->getUserInfo());
        self::assertSame($expectedHost, $uri->getHost());
        self::assertSame($expectedPort, $uri->getPort());
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

    /**
     * Case normalization per RFC 3986 §6.2.2.1.
     */
    #[TestDox('Normalize case: $input => $expected')]
    #[TestWith(['HTTP://example.com/', 'http://example.com/'])]
    #[TestWith(['http://Example.COM/path', 'http://example.com/path'])]
    #[TestWith(['HTTPS://WWW.IETF.ORG/rfc/rfc2396.txt', 'https://www.ietf.org/rfc/rfc2396.txt'])]
    #[TestWith(['http://example.com/%3apath', 'http://example.com/%3Apath'])]
    #[TestWith(['http://user@Host.EXAMPLE.com:80/', 'http://user@host.example.com:80/'])]
    #[TestWith(['http://[2001:DB8::7]/c=GB', 'http://[2001:db8::7]/c=GB'])]
    #[TestWith(['http://example.com/#%7b', 'http://example.com/#%7B'])]
    public function testNormalizeCase(string $input, string $expected): void
    {
        $ref        = UriReference::parse($input);
        $normalized = $ref->normalize(case: true, percentEncoding: false, pathSegment: false);
        self::assertSame($expected, $normalized->toString());
    }

    /**
     * Percent-encoding normalization per RFC 3986 §6.2.2.2.
     */
    #[TestDox('Normalize percent-encoding: $input => $expected')]
    #[TestWith(['http://example.com/%61bc', 'http://example.com/abc'])]
    #[TestWith(['http://example.com/foo%7Ebar', 'http://example.com/foo~bar'])]
    #[TestWith(['http://example.com/foo%2Ebar', 'http://example.com/foo.bar'])]
    #[TestWith(['http://example.com/%2d%5f%2d', 'http://example.com/-_-'])]
    #[TestWith(['http://example.com/%31%32%33', 'http://example.com/123'])]
    #[TestWith(['http://example.com/%2Fpath', 'http://example.com/%2Fpath'])]
    #[TestWith(['http://example.com?%71=value', 'http://example.com?q=value'])]
    #[TestWith(['http://example.com#%73ection', 'http://example.com#section'])]
    public function testNormalizePercentEncoding(string $input, string $expected): void
    {
        $ref        = UriReference::parse($input);
        $normalized = $ref->normalize(case: false, percentEncoding: true, pathSegment: false);
        self::assertSame($expected, $normalized->toString());
    }

    /**
     * Path segment normalization per RFC 3986 §6.2.2.3.
     */
    #[TestDox('Normalize path segment: $input => $expected')]
    #[TestWith(['http://a/b/c/./../../g', 'http://a/g'])]
    #[TestWith(['http://a/./b/../b/c', 'http://a/b/c'])]
    #[TestWith(['http://example.com/./path', 'http://example.com/path'])]
    #[TestWith(['http://example.com/../path', 'http://example.com/path'])]
    #[TestWith(['http://example.com/.', 'http://example.com/'])]
    #[TestWith(['http://example.com/./', 'http://example.com/'])]
    #[TestWith(['http://a/./g', 'http://a/g'])]
    #[TestWith(['http://a/../g', 'http://a/g'])]
    #[TestWith(['g/./h', 'g/h'])]
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
    public function testParseParsesComponents(string $uri, UriReference $expected, bool $expectedIsRFC3986, bool $expectedIsRFC3987, bool $expectedIsRelativeReference, bool $expectedIsAbsoluteURI, bool $expectedIsSuffixReference): void
    {
        $parsed = UriReference::parse($uri);
        self::assertSame($expected->scheme, $parsed->scheme, 'correct scheme');
        self::assertSame($expected->authority, $parsed->authority, 'correct authority');
        self::assertSame($expected->path, $parsed->path, 'correct path');
        self::assertSame($expected->query, $parsed->query, 'correct query');
        self::assertSame($expected->fragment, $parsed->fragment, 'correct fragment');
    }

    #[DataProvider('rfc3986SpecParseProvider')]
    #[TestDox('To string for $uri')]
    public function testToString(string $uri, UriReference $expected, bool $expectedIsRFC3986, bool $expectedIsRFC3987, bool $expectedIsRelativeReference, bool $expectedIsAbsoluteURI, bool $expectedIsSuffixReference): void
    {
        self::assertSame($uri, $expected->toString());
    }

    #[DataProvider('rfc3986SpecParseProvider')]
    #[TestDox('Parse round trip for $uri')]
    public function testParseRoundTrip(string $uri, UriReference $expected, bool $expectedIsRFC3986, bool $expectedIsRFC3987, bool $expectedIsRelativeReference, bool $expectedIsAbsoluteURI, bool $expectedIsSuffixReference): void
    {
        $parsed = UriReference::parse($uri);
        self::assertSame($uri, $parsed->toString());
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

    #[DataProvider('customUrlResolveProvider')]
    #[DataProvider('rfc3986SpecResolveProvider')]
    #[TestDox('Resolve URLs: base=$base, relative=$relative, expected=$expected')]
    public function testResolveURLs(string $base, string $relative, string $expected): void
    {
        $result = UriReference::resolveRelative($base, $relative);
        self::assertSame($expected, $result);
    }
}
