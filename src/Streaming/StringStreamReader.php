<?php

declare(strict_types=1);

namespace FancyRDF\Streaming;

use Override;

use function fclose;
use function fopen;
use function fwrite;
use function is_resource;
use function rewind;

/**
 * StringStreamReader is a StreamReader that reads from a string.
 */
final class StringStreamReader extends StreamReader
{
    public function __construct(public readonly string $string)
    {
    }

    private bool $read = false;

    #[Override]
    protected function read(): string|false
    {
        if ($this->read) {
            return false;
        }

        $this->read = true;

        return $this->string;
    }

    /**
     * Opens a string as a resource stream.
     *
     * @return resource|false */
    public static function open(string $string)
    {
        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            return false;
        }

        $shouldClose = true;
        try {
            if (fwrite($stream, $string) === false) {
                return false;
            }

            if (rewind($stream) === false) {
                return false;
            }

            $shouldClose = false;

            return $stream;
        } finally {
            if ($shouldClose && is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
