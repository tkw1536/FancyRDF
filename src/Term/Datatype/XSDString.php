<?php

declare(strict_types=1);

namespace FancyRDF\Term\Datatype;

use FancyRDF\Exceptions\InvalidLexicalValueError;
use Override;

use function mb_check_encoding;

/** @extends Datatype<string> */
final class XSDString extends Datatype
{
    public const string IRI = 'http://www.w3.org/2001/XMLSchema#string';

    /** @return list<string> */
    #[Override]
    public static function getIRIs(): array
    {
        return [self::IRI];
    }

    /** @throws InvalidLexicalValueError */
    #[Override]
    public function toValue(): string
    {
        if (! mb_check_encoding($this->lexical, 'UTF-8')) {
            throw new InvalidLexicalValueError('string is not valid UTF-8', $this->iri, $this->lexical, $this->language);
        }

        return $this->lexical;
    }

    /** @throws InvalidLexicalValueError */
    #[Override]
    public function toCanonicalForm(): string
    {
        return $this->toValue();
    }
}
