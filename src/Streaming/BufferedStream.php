<?php

declare(strict_types=1);

namespace FancyRDF\Streaming;

use function fread;
use function is_string;
use function max;
use function mb_substr;
use function strcasecmp;
use function strlen;
use function substr;

/**
 * Buffers content from a stream, allowing various read operations.
 */
final class BufferedStream
{
    public const int DEFAULT_READ_CHUNK_SIZE = 8192;

    /** @param resource $source */
    public function __construct(private readonly mixed $source, public readonly int $readChunkSize = self::DEFAULT_READ_CHUNK_SIZE)
    {
    }

    // TODO: Make me private
    public string $buffer = '';
    // TODO: Make me private
    public int $position = 0;

    /**
     * Refill reads data from the underlying stream into the buffer.
     *
     * After a call to refill at least one of the following is true:
     *
     * - there are at least $numBytes read from the stream into the buffer past the current position.
     * - the input stream has ended, and all available bytes are available in the buffer.
     */
    private function refill(int $numBytes = 1): void
    {
        $available = strlen($this->buffer) - $this->position;
        while ($available < $numBytes) {
            $chunk = fread($this->source, max(1, $this->readChunkSize));
            if ($chunk === false || $chunk === '') {
                return;
            }

            $this->buffer .= $chunk;
            $available     = strlen($this->buffer) - $this->position;
        }
    }

    /**
     * Removes any read content from the buffer.
     */
    public function flush(): void
    {
        $this->buffer   = substr($this->buffer, $this->position);
        $this->position = 0;
    }

    private const int MAX_CODE_POINT_LENGTH = 4;

    /**
     * Returns the next unicode character in the input, loading from buffer if needed.
     *
     * @return non-empty-string|null
     *     null if the end of input has been reached.
     */
    public function peekChar(int $offset = 0): string|null
    {
        $this->refill($offset + self::MAX_CODE_POINT_LENGTH);
        $rest = substr($this->buffer, $this->position + $offset);
        if ($rest === '') {
            return null;
        }

        return mb_substr($rest, 0, 1);
    }

    /**
     * Advances the position by the given character.
     */
    public function eatChar(string $char): void
    {
        $this->position += strlen($char);
    }

    /**
     * Peeks at the next character, appends it to dest, and returns it.
     */
    public function appendChar(string &$dest): string|null
    {
        $ch = $this->peekChar();
        if ($ch === null) {
            return null;
        }

        $dest           .= $ch;
        $this->position += strlen($ch);

        return $ch;
    }

    /**
     * Eats a string from the buffer of the given length.
     * If the length is a string, a string of that length is eaten.
     */
    public function eatString(string|int $len): string
    {
        $size = is_string($len) ? strlen($len) : $len;
        $this->refill($size);

        $result          = substr($this->buffer, $this->position, $size);
        $this->position += $size;

        return $result;
    }

    /**
     * Checks if the rest of the input stream starts with the given prefix.
     */
    public function startsWith(string $prefix, bool $ignoreCase = false): bool
    {
        $this->refill(strlen($prefix));

        $value = substr($this->buffer, $this->position, strlen($prefix));

        return $ignoreCase ? strcasecmp($value, $prefix) === 0 : $value === $prefix;
    }
}
