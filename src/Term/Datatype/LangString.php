<?php

declare(strict_types=1);

namespace FancyRDF\Term\Datatype;

use FancyRDF\Exceptions\InvalidLexicalValueError;
use Override;

/** @extends Datatype<array{string, string}> */
final class LangString extends Datatype
{
    public const string IRI = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#langString';

    /** @return list<string> */
    #[Override]
    public static function getIRIs(): array
    {
        return [self::IRI];
    }

    /** @return array{string, string} */
    #[Override]
    public function toValue(): array
    {
        if ($this->language === null || $this->language === '') {
            throw new InvalidLexicalValueError('language is required for LangString literal', $this->lexical, $this->language);
        }

        return [$this->lexical, $this->language];
    }

    /** @throws void */
    #[Override]
    public function toCanonicalForm(): string
    {
        return $this->lexical;
    }
}
