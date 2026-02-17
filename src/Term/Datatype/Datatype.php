<?php

declare(strict_types=1);

namespace FancySparql\Term\Datatype;

/**
 * Datatype represents a value of a specific RDF1.1 datatype.
 *
 * @template-covariant TValue
 */
abstract class Datatype
{
    public function __construct(public readonly string $lexical, public readonly string|null $language = null)
    {
    }

    /**
     * Returns a list of RDF1.1 datatype IRIs supported by this class.
     *
     * @return list<string>
     */
    abstract public static function getIRIs(): array;

    /**
     * Converts this value into a PHP value.
     *
     * @return TValue
     */
    abstract public function toValue(): mixed;

    /**
     * Returns the canonical lexical form (if any) of this value.
     */
    abstract public function toCanonicalForm(): string;

    /**
     * Checks if the value of this datatype is equal to the value of the other datatype.
     *
     * @param Datatype<mixed> $other
     *   The other datatype to compare with.
     */
    public function equals(Datatype $other): bool
    {
        return $other instanceof static && $this->toCanonicalForm() === $other->toCanonicalForm();
    }
}
