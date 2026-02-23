<?php

declare(strict_types=1);

namespace FancyRDF\Streaming;

use Override;

use function fread;
use function max;

/**
 * ResourceStreamReader is a StreamReader that reads from a resource.
 */
final class ResourceStreamReader extends StreamReader
{
    public const int DEFAULT_CHUNK_SIZE = 8192; // 8KB

    /** @param resource $source */
    public function __construct(private readonly mixed $source, public readonly int $chunkSize = self::DEFAULT_CHUNK_SIZE)
    {
    }

    #[Override]
    protected function read(): string|false
    {
        return fread($this->source, max(1, $this->chunkSize));
    }
}
