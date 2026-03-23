<?php

declare(strict_types=1);

namespace FancyRDF\Exceptions;

use AssertionError;

/**
 * Thrown by various parsers to indicate that the input is not compliant with the standard.
 * It may be possible to re-parse in non-strict mode.
 */
final class NonCompliantInputError extends AssertionError
{
}
