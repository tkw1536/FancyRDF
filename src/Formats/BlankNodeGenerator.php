<?php

declare(strict_types=1);

namespace FancyRDF\Formats;

use FancyRDF\Term\BlankNode;

use function is_string;

/**
 * Provides a method to generate unique blank node identifiers.
 *
 * @internal
 */
trait BlankNodeGenerator
{
    private const string PREFIX = '_';

    private int $counter = 0;

    /** @var array<string, non-empty-string> */
    private array $blankNodes = [];

    /**
     * Generates a new blank node.
     *
     * @param string|null $name
     *   If non-null, a string that uniquely identifies this blank node
     *   within the current context.
     *   Passing the same name multiple times will return the same blank node.
     *   If null, a fresh blank with a new unique identifier is returned.
     */
    final protected function blankNode(string|null $name): BlankNode
    {
        // Pick the existing blank node label, or create a new one.
        $id   = is_string($name) ? $this->blankNodes[$name] ?? null : null;
        $id ??= self::PREFIX . ($this->counter++);

        // Store the mapping if we were given a name.
        if (is_string($name)) {
            $this->blankNodes[$name] = $id;
        }

        return new BlankNode($id);
    }
}
