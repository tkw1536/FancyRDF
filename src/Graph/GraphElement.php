<?php

declare(strict_types=1);

namespace FancySparql\Graph;

use FancySparql\Term\Literal;
use FancySparql\Term\Resource;

/**
 * Reprensets a
 *
 * @phpstan-type Triple array{Resource, Resource, Resource|Literal, null}
 * @phpstan-type Quad array{Resource, Resource, Resource|Literal, Resource}
 * @phpstan-type TripleOrQuad Triple|Quad
 */
final class GraphElement
{
    /** you cannot instantiate this class */
    private function __construct()
    {
    }
}
