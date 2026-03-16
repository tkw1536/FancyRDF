<?php

declare(strict_types=1);

namespace FancyRDF\Term\Datatype;

use InvalidArgumentException;
use Throwable;

/**
 * Thrown when a lexical value is invalid for a given datatype.
 */
final class InvalidLexicalValueError extends InvalidArgumentException
{
    /**
     * @param string      $message  A reason why this lexical value is invalid.
     * @param string      $lexical  The lexical form that caused this value to be invalid.
     * @param string|null $language The language tag that caused this value to be invalid, if any.
     */
    public function __construct(string $message, public readonly string $lexical, public readonly string|null $language, Throwable|null $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
