<?php

declare(strict_types=1);

namespace FancyRDF\Streaming;

use Fiber;
use Generator;
use RuntimeException;
use Throwable;

use function array_key_exists;
use function assert;
use function fopen;
use function is_array;
use function is_string;
use function stream_context_create;
use function stream_context_get_options;
use function stream_wrapper_register;
use function strlen;
use function substr;
use function trigger_error;

use const E_USER_WARNING;

/**
 * A class that allows using a generator as a stream.
 */
final class IteratorStream
{
    /**
     * Opens a generator function as a stream.
     *
     * @param callable(): Generator<int, string, mixed, void> $func
     *   A generator function that "yield"s string content of the stream.
     *   The function is called right before the stream is first read.
     * @param int|null                                        $size
     *   The total number of bytes expected to be read from the generator, if known.
     *   If the size does not match what the generator yields, the behavior of the stream is undefined.
     *
     * @return resource
     */
    public static function open(callable $func, int|null $size = null)
    {
        $scheme = self::register();

        $context = stream_context_create([
            $scheme => [
                'context' => new IteratorContext($func, $size),
            ],
        ]);

        $stream = fopen(self::$scheme . '://', 'r', false, $context);
        if ($stream === false) {
            throw new RuntimeException('failed to open stream');
        }

        return $stream;
    }

    /**
     * Opens a fiber as a stream.
     *
     * @param Fiber<TStart, TResume, TReturn, TSuspend> $fiber
     *   The fiber to open as a stream.
     *   If the fiber is not yet started, it will start the fiber.
     *   To send data to the stream, the fiber should be suspended with a string value.
     *   Any non-string values will be ignored.
     * @param int|null                                  $size
     *   The total number of bytes expected to be read from the generator.
     *   If the total size is wrong, the behavior of the stream is undefined.
     *
     * @return resource
     *   The stream resource.
     *
     * @template TStart
     * @template TResume
     * @template TReturn
     * @template TSuspend
     */
    public static function openFiber(Fiber $fiber, int|null $size = null)
    {
        return self::open(static function () use ($fiber) {
            // start the fiber if we haven't yet.
            $data = null;
            if (! $fiber->isStarted()) {
                $data = $fiber->start();
            }

            while ($fiber->isSuspended()) {
                if (is_string($data)) {
                    yield $data;
                }

                $data = $fiber->resume();
            }
        }, $size);
    }

    private static string|null $scheme = null;

    private static function register(): string
    {
        if (self::$scheme === null) {
            self::$scheme = 'FancyRDF-Streaming-IteratorStream';
            stream_wrapper_register(self::$scheme, self::class);
        }

        return self::$scheme;
    }

    /** @var resource|null Stream context as set by the engine when no context is passed to the stream function. */
    public $context;

    private IteratorContext|null $iteratorContext = null;

    /**
     * The currently buffered data from the stream.
     */
    private string $buffer = '';

    /**
     * Whether there is any more data expected to be read from the generator.
     */
    private bool $done = false;

    /**
     * The fiber that is producing the data for the stream.
     *
     * @var Fiber<void, void, void, void>|null
     */
    private Fiber|null $fiber = null;

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- stream wrapper protocol
    public function stream_open(string $path, string $mode, int $options, string|null &$openedPath): bool
    {
        assert($mode === 'r', 'not a valid open mode');
        assert($this->context !== null, 'no stream context provided');

        $scheme = self::$scheme;
        assert(is_string($scheme), 'no scheme provided');

        $contextOptions = stream_context_get_options($this->context);
        assert(
            array_key_exists($scheme, $contextOptions) &&
            is_array($contextOptions[$scheme]) &&
            array_key_exists('context', $contextOptions[$scheme]) &&
            $contextOptions[$scheme]['context'] instanceof IteratorContext,
            'invalid stream context provided',
        );
        $this->iteratorContext = $contextOptions[$scheme]['context'];
        $generator             = $this->iteratorContext->generator;

        $this->fiber = new Fiber(function () use ($generator): void {
            try {
                Fiber::suspend();

                $generator = $generator();
                foreach ($generator as $chunk) {
                    $this->buffer .= $chunk;
                    Fiber::suspend();
                }
            } finally {
                $this->done  = true;
                $this->fiber = null;
            }
        });

        $this->fiber->start();

        return true;
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- stream wrapper protocol
    public function stream_read(int $count): string|false
    {
        // while there is some data, allow the fiber to get it!
        while (
            $this->fiber !== null &&
            $this->fiber->isSuspended() &&
            strlen($this->buffer) < $count &&
            ! $this->done
        ) {
            try {
                $this->fiber->resume();
            } catch (Throwable $e) {
                trigger_error(
                    'IteratorStream produced an error: ' . $e->getMessage() . "\n" . $e->getTraceAsString(),
                    E_USER_WARNING,
                );

                return false;
            }
        }

        $chunk        = substr($this->buffer, 0, $count);
        $this->buffer = substr($this->buffer, $count);

        return $chunk;
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- stream wrapper protocol
    public function stream_eof(): bool
    {
        return $this->done && $this->buffer === '';
    }

    /** @return mixed[] */
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- stream wrapper protocol
    public function stream_stat(): array
    {
        $size = $this->iteratorContext?->size;
        if ($size === null || $size < 0) {
            return [];
        }

        return ['size' => $size];
    }
}
