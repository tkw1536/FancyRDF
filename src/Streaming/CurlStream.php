<?php

declare(strict_types=1);

namespace FancyRDF\Streaming;

use CurlHandle;
use Exception;
use Fiber;
use LogicException;
use Throwable;

use function count;
use function curl_error;
use function curl_exec;
use function curl_getinfo;
use function curl_setopt;
use function explode;
use function strlen;
use function trigger_error;
use function trim;

use const CURLINFO_CONTENT_LENGTH_DOWNLOAD;
use const CURLINFO_RESPONSE_CODE;
use const CURLOPT_HEADERFUNCTION;
use const CURLOPT_WRITEFUNCTION;
use const E_USER_WARNING;

/**
 * A class that allows reading html response data by yielding it.
 *
 * TODO: Test this.
 */
final class CurlStream
{
    /** @var Fiber<void, string, null, void> */
    private readonly Fiber $curl;

    public function __construct(private readonly CurlHandle $handle)
    {
        $headers       = [];
        $didFirstChunk = false;

        $this->curl = new Fiber(function () use (&$headers, &$didFirstChunk): void {
            // setup a callback to record headers and status code
            curl_setopt(
                $this->handle,
                CURLOPT_HEADERFUNCTION,
                static function (CurlHandle $handle, string $data) use (&$headers) {
                    $headers[] = $data;

                    return strlen($data);
                },
            );

            curl_setopt(
                $this->handle,
                CURLOPT_WRITEFUNCTION,
                static function (CurlHandle $handle, string $data) use (&$didFirstChunk) {
                    if (! $didFirstChunk) {
                        $didFirstChunk = true;
                        Fiber::suspend();
                    }

                    try {
                        Fiber::suspend($data);

                        return strlen($data);
                    } catch (Throwable $e) {
                        trigger_error('Error in CurlStream: ' . $e->getMessage(), E_USER_WARNING);

                        return 0;
                    }
                },
            );

            curl_exec($this->handle);
        });

        $this->curl->start();
        if (! $didFirstChunk) {
            throw new Exception(curl_error($this->handle));
        }

        $headersMap = [];
        foreach ($headers as $header) {
            $parts = explode(':', $header, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $headersMap[$parts[0]] = trim($parts[1]);
        }

        $this->headers    = $headersMap;
        $this->statusCode = curl_getinfo($this->handle, CURLINFO_RESPONSE_CODE);

        $length              = (int) curl_getinfo($this->handle, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        $this->contentLength = $length > 0 ? $length : null;
    }

    private readonly int $statusCode;
    /** @var array<string, string> */
    private readonly array $headers;

    /** @var positive-int|null */
    private readonly int|null $contentLength;

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /** @return positive-int|null */
    public function getContentLength(): int|null
    {
        return $this->contentLength;
    }

    /**
     * Returns the headers of the response as an array.
     * This function can only be called once.
     *
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    private bool $bodyRead = false;

    /**
     * Returns the content of the response as a stream.
     * This method may be called at most once.
     *
     * @return resource
     */
    public function getContent()
    {
        if ($this->bodyRead) {
            throw new LogicException('You can only get the content once');
        }

        $this->bodyRead = true;

        return IteratorStream::openFiber($this->curl, $this->contentLength);
    }
}
