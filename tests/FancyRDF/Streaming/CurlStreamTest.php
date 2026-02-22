<?php

declare(strict_types=1);

namespace FancyRDF\Tests\FancyRDF\Streaming;

use FancyRDF\Streaming\CurlStream;
use FancyRDF\Tests\Support\TestServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use XMLReader;

use function curl_init;
use function in_array;
use function stream_get_contents;
use function strlen;
use function strtolower;

final class CurlStreamTest extends TestCase
{
    /** @return array<string, array{int, array<string, string>, string}> */
    public static function serverResponseProvider(): array
    {
        $plainBody = 'Hello, world!';
        $jsonBody  = '{"ok":true}';

        return [
            'plain text 200' => [
                200,
                ['content-type' => 'text/plain', 'content-length' => (string) strlen($plainBody)],
                $plainBody,
            ],
            'json 201' => [
                201,
                ['content-type' => 'application/json', 'x-request-id' => 'abc', 'content-length' => (string) strlen($jsonBody)],
                $jsonBody,
            ],
            'empty body 204' => [
                204,
                ['x-custom' => 'value'],
                '',
            ],
        ];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('serverResponseProvider')]
    #[TestDox('fetches response body, status code and headers from TestServer')]
    public function testFetchesFromTestServer(int $statusCode, array $headers, string $body): void
    {
        $server = new TestServer($statusCode, $headers, $body);

        try {
            $handle = curl_init($server->getBaseUrl() . '/');
            self::assertNotFalse($handle, 'Failed to initialize curl handle.');

            $stream = new CurlStream($handle);

            self::assertSame($statusCode, $stream->getStatusCode());

            $gotHeaders = $stream->getHeaders();
            foreach ($headers as $name => $value) {
                $name = strtolower($name);
                self::assertArrayHasKey($name, $gotHeaders, "Header '" . $name . "' not found in response headers.");
                self::assertTrue(in_array($value, $gotHeaders[$name], true), "Header '" . $name . "' did not contain expected value.");
            }

            $expectedLength = strlen($body) > 0 ? strlen($body) : null;
            self::assertSame($expectedLength, $stream->getContentLength(), 'Content-Length mismatch.');

            $content = stream_get_contents($stream->getContent());
            self::assertSame($body, $content);
        } finally {
            $server->stop();
        }
    }

    #[TestDox('response body can be read via XMLReader')]
    public function testReadResponseViaXmlReader(): void
    {
        $xml    = '<root><foo/><bar/></root>';
        $server = new TestServer(
            200,
            ['content-type' => 'application/xml', 'content-length' => (string) strlen($xml)],
            $xml,
        );

        try {
            $handle = curl_init($server->getBaseUrl() . '/');
            self::assertNotFalse($handle);

            $curlStream = new CurlStream($handle);
            $stream     = $curlStream->getContent();

            $reader = XMLReader::fromStream($stream);
            $names  = [];

            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }

                $names[] = $reader->name;
            }

            $reader->close();

            self::assertSame(['root', 'foo', 'bar'], $names);
        } finally {
            $server->stop();
        }
    }
}
