<?php

declare(strict_types=1);

namespace FancyRDF\Tests\FancyRDF\Formats\TrigReader;

use FancyRDF\Formats\TrigReader\TrigReader;
use FancyRDF\Formats\TrigReader\TrigToken;
use FancyRDF\Streaming\ResourceStreamReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

use function fopen;
use function fwrite;
use function rewind;
use function str_repeat;
use function trigger_error;

use const E_USER_WARNING;

final class TrigReaderTest extends TestCase
{
    /** @return resource */
    private static function openString(string $input): mixed
    {
        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            throw new RuntimeException('Failed to open memory stream');
        }

        if (fwrite($stream, $input) === false) {
            throw new RuntimeException('Failed to write to memory stream');
        }

        if (rewind($stream) === false) {
            throw new RuntimeException('Failed to rewind memory stream');
        }

        return $stream;
    }

    /** @param list<array{TrigToken, string}> $expected */
    #[DataProvider('tokenizeProvider')]
    #[TestDox('tokenizes input to expected token sequence')]
    public function testTokenize(string $input, array $expected): void
    {
        $stream = self::openString($input);
        $reader = new TrigReader(new ResourceStreamReader($stream));
        $tokens = [];
        try {
            while ($reader->next()) {
                $tokens[] = [$reader->getTokenType(), $reader->getTokenValue()];
            }
        } catch (Throwable $e) {
            trigger_error($e->getMessage(), E_USER_WARNING);
        }

        $tokens[] = [TrigToken::EndOfInput, $reader->getTokenValue()];
        self::assertSame($expected, $tokens);
    }

    /** @return array<string, array{string, list<array{TrigToken, string}>}> */
    public static function tokenizeProvider(): array
    {
        $shortTrigSnippet = <<<'TRIG'
@prefix ex: <http://example.org/> .
@prefix : <http://example.org/def#> .

:G1 { :Monica a ex:Person ;
          ex:name "Monica Murphy" . }
TRIG;

        return [
            'keywords and punctuation' => [
                '@prefix @base a true false . ; , [ ] ( ) { } ^^',
                [
                    [TrigToken::AtPrefix, '@prefix'],
                    [TrigToken::AtBase, '@base'],
                    [TrigToken::A, 'a'],
                    [TrigToken::True, 'true'],
                    [TrigToken::False, 'false'],
                    [TrigToken::Dot, '.'],
                    [TrigToken::Semicolon, ';'],
                    [TrigToken::Comma, ','],
                    [TrigToken::LSquare, '['],
                    [TrigToken::RSquare, ']'],
                    [TrigToken::LParen, '('],
                    [TrigToken::RParen, ')'],
                    [TrigToken::LCurly, '{'],
                    [TrigToken::RCurly, '}'],
                    [TrigToken::HatHat, '^^'],
                    [TrigToken::EndOfInput, ''],
                ],
            ],
            'case-insensitive GRAPH, PREFIX, BASE' => [
                'GRAPH prefix BASE graph PREFIX base',
                [
                    [TrigToken::Graph, 'GRAPH'],
                    [TrigToken::Prefix, 'prefix'],
                    [TrigToken::Base, 'BASE'],
                    [TrigToken::Graph, 'graph'],
                    [TrigToken::Prefix, 'PREFIX'],
                    [TrigToken::Base, 'base'],
                    [TrigToken::EndOfInput, ''],
                ],
            ],
            'IRI reference' => [
                '<http://example.org/>',
                [
                    [TrigToken::IriRef, '<http://example.org/>'],
                    [TrigToken::EndOfInput, ''],
                ],
            ],
            'string literals' => [
                '"hello" \'world\' "with\\nnewline"',
                [
                    [TrigToken::String, '"hello"'],
                    [TrigToken::String, "'world'"],
                    [TrigToken::String, '"with\\nnewline"'],
                    [TrigToken::EndOfInput, ''],
                ],
            ],
            'numbers' => [
                '42 -7 3.14 1e2 2.5e-1',
                [
                    [TrigToken::Integer, '42'],
                    [TrigToken::Integer, '-7'],
                    [TrigToken::Decimal, '3.14'],
                    [TrigToken::Double, '1e2'],
                    [TrigToken::Double, '2.5e-1'],
                    [TrigToken::EndOfInput, ''],
                ],
            ],
            'blank node label and ANON' => [
                '_:b0 [] _:label',
                [
                    [TrigToken::BlankNodeLabel, '_:b0'],
                    [TrigToken::LSquare, '['],
                    [TrigToken::RSquare, ']'],
                    [TrigToken::BlankNodeLabel, '_:label'],
                    [TrigToken::EndOfInput, ''],
                ],
            ],
            'prefixed names' => [
                'ex:foo :bar rdf:type',
                [
                    [TrigToken::PnameLn, 'ex:foo'],
                    [TrigToken::PnameLn, ':bar'],
                    [TrigToken::PnameLn, 'rdf:type'],
                    [TrigToken::EndOfInput, ''],
                ],
            ],
            'skips whitespace and comments' => [
                "  \t\n# comment\n.",
                [
                    [TrigToken::Dot, '.'],
                    [TrigToken::EndOfInput, ''],
                ],
            ],
            'short TriG snippet' => [
                $shortTrigSnippet,
                [
                    [TrigToken::AtPrefix, '@prefix'],
                    [TrigToken::PnameNs, 'ex:'],
                    [TrigToken::IriRef, '<http://example.org/>'],
                    [TrigToken::Dot, '.'],
                    [TrigToken::AtPrefix, '@prefix'],
                    [TrigToken::PnameNs, ':'],
                    [TrigToken::IriRef, '<http://example.org/def#>'],
                    [TrigToken::Dot, '.'],
                    [TrigToken::PnameLn, ':G1'],
                    [TrigToken::LCurly, '{'],
                    [TrigToken::PnameLn, ':Monica'],
                    [TrigToken::A, 'a'],
                    [TrigToken::PnameLn, 'ex:Person'],
                    [TrigToken::Semicolon, ';'],
                    [TrigToken::PnameLn, 'ex:name'],
                    [TrigToken::String, '"Monica Murphy"'],
                    [TrigToken::Dot, '.'],
                    [TrigToken::RCurly, '}'],
                    [TrigToken::EndOfInput, ''],
                ],
            ],
            'empty input' => [
                '',
                [[TrigToken::EndOfInput, '']],
            ],
            'single quoted string' => [
                '"hello"',
                [
                    [TrigToken::String, '"hello"'],
                    [TrigToken::EndOfInput, ''],
                ],
            ],
            'single blank node label' => [
                '_:x',
                [
                    [TrigToken::BlankNodeLabel, '_:x'],
                    [TrigToken::EndOfInput, ''],
                ],
            ],
            'single integer' => [
                '123',
                [
                    [TrigToken::Integer, '123'],
                    [TrigToken::EndOfInput, ''],
                ],
            ],
            'single decimal' => [
                '1.5',
                [
                    [TrigToken::Decimal, '1.5'],
                    [TrigToken::EndOfInput, ''],
                ],
            ],
        ];
    }

    #[TestDox('streaming reader yields same token sequence when using small chunk size')]
    public function testStreamingReadsInChunks(): void
    {
        $chunk  = str_repeat(' ', 500) . '.' . str_repeat(' ', 500);
        $stream = self::openString($chunk);
        $reader = new TrigReader(new ResourceStreamReader($stream, 256));
        $tokens = [];
        while ($reader->next()) {
            $tokens[] = [$reader->getTokenType(), $reader->getTokenValue()];
        }

        $tokens[] = [TrigToken::EndOfInput, $reader->getTokenValue()];
        $expected = [
            [TrigToken::Dot, '.'],
            [TrigToken::EndOfInput, ''],
        ];
        self::assertSame($expected, $tokens);
    }
}
