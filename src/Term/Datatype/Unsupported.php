<?php

declare(strict_types=1);

namespace FancyRDF\Term\Datatype;

use FancyRDF\Exceptions\UnsupportedLexicalValueError;
use Override;

/**
 * A special datatype class that is used to represent unsupported datatypes.
 *
 * @extends Datatype<never>
 */
final class Unsupported extends Datatype
{
    /** @return array{} */
    #[Override]
    public static function getIRIs(): array
    {
        return [];
    }

    /**
     * @return never
     *
     * @throws UnsupportedLexicalValueError
     */
    #[Override]
    public function toValue(): mixed
    {
        throw new UnsupportedLexicalValueError('unsupported datatype', $this->iri, $this->lexical, $this->language);
    }

    /**
     * @return never
     *
     * @throws UnsupportedLexicalValueError
     */
    #[Override]
    public function toCanonicalForm(): string
    {
        throw new UnsupportedLexicalValueError('unsupported datatype', $this->iri, $this->lexical, $this->language);
    }
}
