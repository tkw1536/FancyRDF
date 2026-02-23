<?php

declare(strict_types=1);

namespace FancyRDF\Streaming;

use InvalidArgumentException;

use function mb_convert_case;
use function mb_substr;
use function strlen;
use function substr;

use const MB_CASE_FOLD_SIMPLE;

/**
 * StreamReader represents a stream of bytes that can be read and consumed.
 */
abstract class StreamReader
{
    // ================================
    // = Buffer management
    // ================================

    /**
     * The underlying buffer of bytes.
     */
    private string $buffer = '';

    /**
     * Reads data from the underlying stream into $this->buffer.
     *
     * After a call either len($this->buffer) >= $bytes or the input stream has ended.
     */
    private function buffer(int $bytes): void
    {
        while (strlen($this->buffer) < $bytes) {
            $chunk = $this->read();
            if ($chunk === false || $chunk === '') {
                return;
            }

            $this->buffer .= $chunk;
        }
    }

    /**
     * Reads the next chunk of bytes from the underlying data source.
     * When an error occurs, or no more data is available, either false or an empty string may be returned.
     *
     * It is up to the implementation to decide how many bytes to read at once.
     */
    abstract protected function read(): string|false;

    // ================================
    // = Peeking
    // ================================

    private const int MAX_CODE_POINT_LENGTH = 4;

    /**
     * Returns the next unicode code point in the input.
     *
     * @param int $offset
     *   An offset in number of bytes to read from the current position.
     *
     * @return non-empty-string|null
     *   The next unicode code point, or null if the end of input has been reached.
     *
     * @throws InvalidArgumentException if $offset is negative.
     */
    public function peek(int $offset = 0): string|null
    {
        if ($offset < 0) {
            throw new InvalidArgumentException('offset must be non-negative');
        }

        $this->buffer($offset + self::MAX_CODE_POINT_LENGTH);
        $rest = substr($this->buffer, $offset);
        if ($rest === '') {
            return null;
        }

        return mb_substr($rest, 0, 1, 'UTF-8');
    }

    /**
     * Peeks at the next characters in input, checking if they start with the given prefix.
     *
     * @param string $prefix
     *   The prefix to check for.
     * @param bool   $ignoreCase
     *   If true, the prefix is compared case-insensitively.
     *
     * @return bool
     *   True if $prefix is a prefix of the next characters in input.
     *   If the input ends before the prefix can be matched, false is returned.
     */
    public function peekPrefix(string $prefix, bool $ignoreCase = false): bool
    {
        $this->buffer(strlen($prefix));
        $value = substr($this->buffer, 0, strlen($prefix));

        return $ignoreCase
            ? mb_convert_case($value, MB_CASE_FOLD_SIMPLE) === mb_convert_case($prefix, MB_CASE_FOLD_SIMPLE)
            : $value === $prefix;
    }

    // ================================
    // = Consuming
    // ================================

    /**
     * Consumes and returns the given number of bytes from the input.
     * If there are fewer than $bytes bytes available, the remaining bytes are returned.
     *
     * @throws InvalidArgumentException if $bytes is negative.
     */
    public function consume(int $bytes): string
    {
        if ($bytes < 0) {
            throw new InvalidArgumentException('offset must be non-negative');
        }

        $this->buffer($bytes);

        $result       = substr($this->buffer, 0, $bytes);
        $this->buffer = substr($this->buffer, $bytes);

        return $result;
    }
}
