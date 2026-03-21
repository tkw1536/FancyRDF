<?php

declare(strict_types=1);

namespace FancyRDF\Term\Datatype;

use FancyRDF\Exceptions\InvalidLexicalValueError;
use Override;

/** @extends Datatype<bool> */
final class XSDBoolean extends Datatype
{
    public const string IRI = 'http://www.w3.org/2001/XMLSchema#boolean';

    /** @return list<string> */
    #[Override]
    public static function getIRIs(): array
    {
        return [self::IRI];
    }

    #[Override]
    public function toValue(): bool
    {
        switch ($this->lexical) {
            case 'true':
            case '1':
                return true;

            case 'false':
            case '0':
                return false;
        }

        throw new InvalidLexicalValueError('invalid boolean literal', $this->lexical, $this->language);
    }

    #[Override]
    public function toCanonicalForm(): string
    {
        return $this->toValue() ? 'true' : 'false';
    }
}
