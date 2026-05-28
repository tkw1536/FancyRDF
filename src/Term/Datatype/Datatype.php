<?php

declare(strict_types=1);

namespace FancyRDF\Term\Datatype;

use FancyRDF\Exceptions\InvalidLexicalValueError;
use FancyRDF\Exceptions\UnsupportedLexicalValueError;

/**
 * Datatype represents a value of a specific RDF1.1 datatype.
 *
 * @template-covariant TValue
 */
abstract class Datatype
{
    public function __construct(public readonly string $lexical, public readonly string|null $language, public readonly string $iri)
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
     * @throws UnsupportedLexicalValueError if the value for this datatype is unsupported and cannot be converted.
     */
    abstract public function toValue(): mixed;

    /**
     * Returns the canonical lexical form (if any) of this value.
     *
     * @throws InvalidLexicalValueError if the value for this datatype is invalid and cannot be converted to a canonical form.
     * @throws UnsupportedLexicalValueError if the value for this datatype is unsupported and cannot be converted to a canonical form.
     */
    abstract public function toCanonicalForm(): string;

    /**
     * Like toCanonicalForm, but if an exception is thrown null is returned instead.
     *
     * @return string
     */
    final public function toCanonical(): string|null
    {
        try {
            return $this->toCanonicalForm();
        } catch (InvalidLexicalValueError) {
            return null;
        } catch (UnsupportedLexicalValueError) {
            return null;
        }
    }

    /**
     * Checks if the value of this datatype is equal to the value of the other datatype.
     *
     * This function may return false negatives if canonicalization fails, or the datatype is unsupported.
     * It never returns false positives.
     *
     * @param Datatype<mixed> $other
     *   The other datatype to compare with.
     */
    final public function equals(Datatype $other): bool
    {
        if (! $other instanceof static) {
            return false;
        }

        // if the lexical and language tags are identical, then we don't need to canonicalize.
        // and can immediately return true.
        if ($other->lexical === $this->lexical && $other->language === $this->language) {
            return true;
        }

        return self::doesEqual($other);
    }

    /**
     * Implements comparison of two instances of this datatype.
     *
     * This function is called internally by the implementation of the equals method.
     * It can assume that:
     *
     * - $other is an instance of the same class as $other.
     * - There is a difference between the lexical forms and language tags of the two instances.
     *
     * The default implementation attempts to canonicalize both literals, and compares the resulting canonical lexical forms.
     * If either canonicalization fails, the underlying lexical form is used instead.
     *
     * @param static $other
     *   The other instance to compare with.
     */
    protected function doesEqual(Datatype $other): bool
    {
        return ($this->toCanonical() ?? $this->lexical) === ($other->toCanonical() ?? $other->lexical);
    }
}
