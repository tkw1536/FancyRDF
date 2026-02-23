<?php

declare(strict_types=1);

namespace FancyRDF\Tests\FancyRDF\Formats\TrigReader;

use FancyRDF\Formats\TrigReader\TrigReader;
use FancyRDF\Formats\TrigReader\TrigTokenType;
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

    /** @param list<array{TrigTokenType, string}> $expected */
    #[DataProvider('tokenizeProvider')]
    #[TestDox('tokenizes input to expected token sequence')]
    public function testTokenize(string $input, array $expected): void
    {
        $stream = self::openString($input);
        $reader = new TrigReader($stream);
        $tokens = [];
        try {
            while ($reader->next()) {
                $tokens[] = [$reader->getTokenType(), $reader->getTokenValue()];
            }
        } catch (Throwable $e) {
            trigger_error($e->getMessage(), E_USER_WARNING);
        }

        $tokens[] = [TrigTokenType::EndOfInput, $reader->getTokenValue()];
        self::assertSame($expected, $tokens);
    }

    /** @return array<string, array{string, list<array{TrigTokenType, string}>}> */
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
                    [TrigTokenType::AtPrefix, '@prefix'],
                    [TrigTokenType::AtBase, '@base'],
                    [TrigTokenType::A, 'a'],
                    [TrigTokenType::True, 'true'],
                    [TrigTokenType::False, 'false'],
                    [TrigTokenType::Dot, '.'],
                    [TrigTokenType::Semicolon, ';'],
                    [TrigTokenType::Comma, ','],
                    [TrigTokenType::LSquare, '['],
                    [TrigTokenType::RSquare, ']'],
                    [TrigTokenType::LParen, '('],
                    [TrigTokenType::RParen, ')'],
                    [TrigTokenType::LCurly, '{'],
                    [TrigTokenType::RCurly, '}'],
                    [TrigTokenType::HatHat, '^^'],
                    [TrigTokenType::EndOfInput, ''],
                ],
            ],
            'case-insensitive GRAPH, PREFIX, BASE' => [
                'GRAPH prefix BASE graph PREFIX base',
                [
                    [TrigTokenType::Graph, 'GRAPH'],
                    [TrigTokenType::Prefix, 'prefix'],
                    [TrigTokenType::Base, 'BASE'],
                    [TrigTokenType::Graph, 'graph'],
                    [TrigTokenType::Prefix, 'PREFIX'],
                    [TrigTokenType::Base, 'base'],
                    [TrigTokenType::EndOfInput, ''],
                ],
            ],
            'IRI reference' => [
                '<http://example.org/>',
                [
                    [TrigTokenType::IriRef, '<http://example.org/>'],
                    [TrigTokenType::EndOfInput, ''],
                ],
            ],
            'string literals' => [
                '"hello" \'world\' "with\\nnewline"',
                [
                    [TrigTokenType::String, '"hello"'],
                    [TrigTokenType::String, "'world'"],
                    [TrigTokenType::String, '"with\\nnewline"'],
                    [TrigTokenType::EndOfInput, ''],
                ],
            ],
            'numbers' => [
                '42 -7 3.14 1e2 2.5e-1',
                [
                    [TrigTokenType::Integer, '42'],
                    [TrigTokenType::Integer, '-7'],
                    [TrigTokenType::Decimal, '3.14'],
                    [TrigTokenType::Double, '1e2'],
                    [TrigTokenType::Double, '2.5e-1'],
                    [TrigTokenType::EndOfInput, ''],
                ],
            ],
            'blank node label and ANON' => [
                '_:b0 [] _:label',
                [
                    [TrigTokenType::BlankNodeLabel, '_:b0'],
                    [TrigTokenType::LSquare, '['],
                    [TrigTokenType::RSquare, ']'],
                    [TrigTokenType::BlankNodeLabel, '_:label'],
                    [TrigTokenType::EndOfInput, ''],
                ],
            ],
            'prefixed names' => [
                'ex:foo :bar rdf:type',
                [
                    [TrigTokenType::PnameLn, 'ex:foo'],
                    [TrigTokenType::PnameLn, ':bar'],
                    [TrigTokenType::PnameLn, 'rdf:type'],
                    [TrigTokenType::EndOfInput, ''],
                ],
            ],
            'skips whitespace and comments' => [
                "  \t\n# comment\n.",
                [
                    [TrigTokenType::Dot, '.'],
                    [TrigTokenType::EndOfInput, ''],
                ],
            ],
            'short TriG snippet' => [
                $shortTrigSnippet,
                [
                    [TrigTokenType::AtPrefix, '@prefix'],
                    [TrigTokenType::PnameNs, 'ex:'],
                    [TrigTokenType::IriRef, '<http://example.org/>'],
                    [TrigTokenType::Dot, '.'],
                    [TrigTokenType::AtPrefix, '@prefix'],
                    [TrigTokenType::PnameNs, ':'],
                    [TrigTokenType::IriRef, '<http://example.org/def#>'],
                    [TrigTokenType::Dot, '.'],
                    [TrigTokenType::PnameLn, ':G1'],
                    [TrigTokenType::LCurly, '{'],
                    [TrigTokenType::PnameLn, ':Monica'],
                    [TrigTokenType::A, 'a'],
                    [TrigTokenType::PnameLn, 'ex:Person'],
                    [TrigTokenType::Semicolon, ';'],
                    [TrigTokenType::PnameLn, 'ex:name'],
                    [TrigTokenType::String, '"Monica Murphy"'],
                    [TrigTokenType::Dot, '.'],
                    [TrigTokenType::RCurly, '}'],
                    [TrigTokenType::EndOfInput, ''],
                ],
            ],
            'empty input' => [
                '',
                [[TrigTokenType::EndOfInput, '']],
            ],
            'single quoted string' => [
                '"hello"',
                [
                    [TrigTokenType::String, '"hello"'],
                    [TrigTokenType::EndOfInput, ''],
                ],
            ],
            'single blank node label' => [
                '_:x',
                [
                    [TrigTokenType::BlankNodeLabel, '_:x'],
                    [TrigTokenType::EndOfInput, ''],
                ],
            ],
            'single integer' => [
                '123',
                [
                    [TrigTokenType::Integer, '123'],
                    [TrigTokenType::EndOfInput, ''],
                ],
            ],
            'single decimal' => [
                '1.5',
                [
                    [TrigTokenType::Decimal, '1.5'],
                    [TrigTokenType::EndOfInput, ''],
                ],
            ],
        ];
    }

    #[TestDox('streaming reader yields same token sequence when using small chunk size')]
    public function testStreamingReadsInChunks(): void
    {
        $chunk  = str_repeat(' ', 500) . '.' . str_repeat(' ', 500);
        $stream = self::openString($chunk);
        $reader = new TrigReader($stream, 256);
        $tokens = [];
        while ($reader->next()) {
            $tokens[] = [$reader->getTokenType(), $reader->getTokenValue()];
        }

        $tokens[] = [TrigTokenType::EndOfInput, $reader->getTokenValue()];
        $expected = [
            [TrigTokenType::Dot, '.'],
            [TrigTokenType::EndOfInput, ''],
        ];
        self::assertSame($expected, $tokens);
    }
}
