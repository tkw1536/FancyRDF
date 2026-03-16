<?php

declare(strict_types=1);

namespace FancyRDF\Term\Datatype;

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
     *
     * @throws InvalidLexicalValueError if the value for this datatype is invalid and cannot be converted.
     */
    abstract public function toValue(): mixed;

    /**
     * Returns the canonical lexical form (if any) of this value.
     *
     * @throws InvalidLexicalValueError if the value for this datatype is invalid and cannot be converted to a canonical form.
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
        if (! $other instanceof static) {
            return false;
        }

        // if the lexical and language tags are identical, then we don't need to canonicalize.
        // and can immediately return true.
        if ($other->lexical === $this->lexical && $other->language === $this->language) {
            return true;
        }

        // If not, we need to canonicalize both values and compare the results.
        try {
            return $this->toCanonicalForm() === $other->toCanonicalForm();
        } catch (InvalidLexicalValueError) {
            return false;
        }
    }
}
