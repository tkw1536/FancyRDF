<?php

declare(strict_types=1);

namespace FancySparql\Tests\FancySparql\Url;

use FancySparql\Url\Url;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UrlTest extends TestCase
{
   /** @return array<string, array{string, string, string}> */
    public static function customURLsProvider(): array
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

    /** @return array<string, array{string, string, string}> */
    public static function rfc3986URLsProvider(): array
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
     * Examples from RFC 3986.
     *
     * @return array<string, array{string, Url}>
     */
    public static function rfcUrlParseExamplesProvider(): array
    {
        $examples = [
            // RFC 3986 §1.1.2 Examples
            'ftp://ftp.is.co.za/rfc/rfc1808.txt' => new Url('ftp', 'ftp.is.co.za', '/rfc/rfc1808.txt', null, null),
            'http://www.ietf.org/rfc/rfc2396.txt' => new Url('http', 'www.ietf.org', '/rfc/rfc2396.txt', null, null),
            'ldap://[2001:db8::7]/c=GB?objectClass?one' => new Url('ldap', '[2001:db8::7]', '/c=GB', 'objectClass?one', null),
            'mailto:John.Doe@example.com' => new Url('mailto', null, 'John.Doe@example.com', null, null),
            'news:comp.infosystems.www.servers.unix' => new Url('news', null, 'comp.infosystems.www.servers.unix', null, null),
            'tel:+1-816-555-1212' => new Url('tel', null, '+1-816-555-1212', null, null),
            'telnet://192.0.2.16:80/' => new Url('telnet', '192.0.2.16:80', '/', null, null),
            'urn:oasis:names:specification:docbook:dtd:xml:4.1.2' => new Url('urn', null, 'oasis:names:specification:docbook:dtd:xml:4.1.2', null, null),

            // RFC 3986 §5.4 base and components
            'http://a/b/c/d;p?q' => new Url('http', 'a', '/b/c/d;p', 'q', null),
            'http://a/b/c/d;p?q#s' => new Url('http', 'a', '/b/c/d;p', 'q', 's'),
            'http://a/b/c/g/' => new Url('http', 'a', '/b/c/g/', null, null),
            'http://g' => new Url('http', 'g', '', null, null),
            'http://a/b/c/g?y/./x' => new Url('http', 'a', '/b/c/g', 'y/./x', null),
            'http://a/b/c/g#s/../x' => new Url('http', 'a', '/b/c/g', null, 's/../x'),

            // Relative references: path-only, query-only, fragment-only
            'g' => new Url(null, null, 'g', null, null),
            'g/' => new Url(null, null, 'g/', null, null),
            './g' => new Url(null, null, './g', null, null),
            '/g' => new Url(null, null, '/g', null, null),
            '?y' => new Url(null, null, '', 'y', null),
            '#s' => new Url(null, null, '', null, 's'),
            'g?y' => new Url(null, null, 'g', 'y', null),
            'g#s' => new Url(null, null, 'g', null, 's'),
            'g?y#s' => new Url(null, null, 'g', 'y', 's'),

            // Network path reference
            '//example.com/path' => new Url(null, 'example.com', '/path', null, null),
            '//g' => new Url(null, 'g', '', null, null),

            // Empty
            '' => new Url(null, null, '', null, null),
        ];

        $cases = [];
        foreach ($examples as $uri => $expected) {
            $cases[$uri] = [
                $uri,
                $expected,
            ];
        }

        return $cases;
    }

    /**
     * fromString() parses into scheme, authority, path, query, fragment per RFC 3986 §3.
     */
    #[DataProvider('rfcUrlParseExamplesProvider')]
    public function testFromStringParsesComponents(string $uri, Url $expected): void
    {
        $parsed = Url::fromString($uri);
        self::assertSame($expected->scheme, $parsed->scheme, 'scheme');
        self::assertSame($expected->authority, $parsed->authority, 'authority');
        self::assertSame($expected->path, $parsed->path, 'path');
        self::assertSame($expected->query, $parsed->query, 'query');
        self::assertSame($expected->fragment, $parsed->fragment, 'fragment');
    }

    #[DataProvider('rfcUrlParseExamplesProvider')]
    public function testToString(string $uri, Url $expected): void
    {
        $parsed = Url::fromString($uri);
        self::assertSame($uri, $expected->toString());
    }

    #[DataProvider('rfcUrlParseExamplesProvider')]
    public function testToStringRoundTrip(string $uri, Url $expected): void
    {
        $parsed = Url::fromString($uri);
        self::assertSame($uri, $parsed->toString());
    }

    #[DataProvider('customURLsProvider')]
    #[DataProvider('rfc3986URLsProvider')]
    public function testResolveURLs(string $base, string $relative, string $expected): void
    {
        $result = Url::resolve($base, $relative);
        self::assertSame($expected, $result);
    }
}
