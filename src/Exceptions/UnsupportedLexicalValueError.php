<?php

declare(strict_types=1);

namespace FancyRDF\Exceptions;

use InvalidArgumentException;
use Throwable;

/**
 * Thrown when a lexical value is not supported by this implementation.
 */
final class UnsupportedLexicalValueError extends InvalidArgumentException
{
    /**
     * @param string      $message  A reason why this lexical value is unsupported.
     * @param string      $iri      The IRI of the datatype that is unsupported.
     * @param string      $lexical  The lexical form that is unsupported.
     * @param string|null $language The language tag that is unsupported, if any.
     */
    public function __construct(string $message, public readonly string $iri, public readonly string $lexical, public readonly string|null $language, Throwable|null $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
