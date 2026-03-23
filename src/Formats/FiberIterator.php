<?php

declare(strict_types=1);

namespace FancyRDF\Formats;

use FancyRDF\Exceptions\NonCompliantInputError;
use Fiber;
use InvalidArgumentException;
use IteratorAggregate;
use Override;
use RuntimeException;
use Traversable;

use function trigger_error;

use const E_USER_NOTICE;

/**
 * A class that emits from a fiber.
 *
 * @implements IteratorAggregate<int|string, T>
 * @template T
 */
abstract class FiberIterator implements IteratorAggregate
{
    // =================================
    // General setup and fiber handling.
    // =================================

    /** @var bool if the getIterator() method has been previously called */
    private bool $getIteratorStarted = false;

    /** @return Traversable<int|string, T> */
    #[Override]
    public function getIterator(): Traversable
    {
        if ($this->getIteratorStarted) {
            trigger_error('FiberIterator::getIterator(): Can only be called once. Returning empty iterator. ', E_USER_NOTICE);

            return;
        }

        $this->getIteratorStarted = true;

        // To allow for more structured code, instead of "yield"ing directly
        // we use a fiber to run the parsing logic.
        // This allows us to have "yield"-like functionality deep within function calls.
        //
        // To emit a triple or quad, the fiber is suspended with that value.
        // This function then yields the triple, and resumes the fiber
        // once control is passed back to this function.
        //
        // The primary parsing logic is implemented in the doParse() function.
        // The emit() function is a type-safe helper that performs the
        // suspension of the fiber.
        //
        // [1]: https://www.php.net/manual/en/language.fibers.php

        /** @var Fiber<T, void, void, T> $fiber */
        $fiber = new Fiber([$this, 'doIterate']);

        $value = $fiber->start();
        if ($value !== null) {
            yield $value;
        }

        while ($fiber->isSuspended()) {
            $value = $fiber->resume();
            if ($value === null) {
                continue;
            }

            yield $value;
        }
    }

    /**
     * Emits a set of quads or triples.
     *
     * @param T $quads The quads or triples to emit
     */
    protected function emit(mixed ...$quads): void
    {
        foreach ($quads as $quad) {
            Fiber::suspend($quad);
        }
    }

    /**
     * Performs the actual iteration.
     *
     * It should call the emit() function at any point to suspend itself and emit a value.
     *
     * @throws RuntimeException
     * @throws InvalidArgumentException
     * @throws NonCompliantInputError
     */
    abstract protected function doIterate(): void;
}
