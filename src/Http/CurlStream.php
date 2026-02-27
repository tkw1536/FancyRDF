<?php

declare(strict_types=1);

namespace FancyRDF\Http;

use CurlHandle;
use Fiber;
use LogicException;
use RuntimeException;
use Throwable;

use function count;
use function curl_error;
use function curl_exec;
use function curl_getinfo;
use function curl_setopt;
use function explode;
use function strlen;
use function strtolower;
use function trigger_error;
use function trim;

use const CURLINFO_CONTENT_LENGTH_DOWNLOAD;
use const CURLINFO_RESPONSE_CODE;
use const CURLOPT_HEADERFUNCTION;
use const CURLOPT_WRITEFUNCTION;
use const E_USER_WARNING;

/**
 * A class that allows reading the body of a HTTP response from a CurlHandle using php's stream interface.
 */
final class CurlStream
{
    /** @var Fiber<void, string, null, void> */
    private readonly Fiber $curl;

    /**
     * Constructs a new CurlStream instance from a handle.
     *
     * @param CurlHandle $handle
     *   A not yet executed curl handle.
     *   The options CURLOPT_HEADERFUNCTION and CURLOPT_WRITEFUNCTION will be overwritten, all other options are left unchanged.
     *
     * @return void
     *
     * @throws RuntimeException If the curl handle returns an error.
     */
    public function __construct(private readonly CurlHandle $handle)
    {
        // The key idea is to have a generator which "yields" individual chunks of data received in the response handle.
        // This can then be feed to the IteratorStream class to create the actual stream.
        //
        // Streaming data from curl is achieved using CURLOPT_WRITEFUNCTION [1].
        // As this is a callback, and we cannot directly "yield" from it, and make use of a Fiber [2].
        // The fiber suspends with each chunk's value as a string.
        // We need to additionally handle storing the response code and headers.
        // Headers are processed using the CURLOPT_HEADERFUNCTION [3] callback.
        //
        // In a regular curl request, these callbacks happen in order:
        //
        // curl_exec() -> CURLOPT_HEADERFUNCTION -> CURLOPT_WRITEFUNCTION
        //
        // Note that it is perfectly legitimate for curl to never call either callback,
        // in particular if the response contains no headers, or if no body data (HTTP 204) is present.
        //
        // We introduce an additional fiber suspension the first time CURLOPT_WRITEFUNCTION is called.
        // This is intended to indicate to the caller that headers have now been fully processed and are safe for user access.
        // If this never gets called, then the fiber terminates immediately after the call to curl_exec().
        // We keep track of this with an additional boolean.
        //
        //
        // [1]: https://www.php.net/manual/en/curl.constants.php#constant.curlopt-writefunction
        // [2]: https://www.php.net/manual/en/language.fibers.php
        // [3]: https://www.php.net/manual/en/curl.constants.php#constant.curlopt-headerfunction

        $headers       = [];
        $didFirstChunk = false;

        $this->curl = new Fiber(function () use (&$headers, &$didFirstChunk): void {
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

        // Fiber has returned, either because CURLOPT_WRITEFUNCTION was called, or because curl_exec() returned an error.
        // Determine if there was an error.
        if (! $didFirstChunk) {
            $error = curl_error($this->handle);
            if ($error !== '') {
                throw new RuntimeException('Curl request failed: ' . $error);
            }
        }

        // It is now safe to access meta data from the response, as all header data has been processed at this point.
        $this->statusCode = curl_getinfo($this->handle, CURLINFO_RESPONSE_CODE);

        $length              = (int) curl_getinfo($this->handle, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        $this->contentLength = $length > 0 ? $length : null;

        // Process and group the headers into a proper map.
        // We need to split "key: value" pairs and group them together.
        $headersMap = [];
        foreach ($headers as $header) {
            $parts = explode(':', $header, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $key   = strtolower(trim($parts[0]));
            $value = trim($parts[1]);

            if (! isset($headersMap[$key])) {
                $headersMap[$key] = [];
            }

            $headersMap[$key][] = $value;
        }

        $this->headers = $headersMap;
    }

    private readonly int $statusCode;
    /** @var array<string, non-empty-list<string>> */
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
     *
     * @return array<string, non-empty-list<string>>
     *   A map from header name to all encountered header values.
     *   The keys are guaranteed to be lowercase.
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
