<?php

declare(strict_types=1);

namespace FancySparql\Term\Datatype;

use Override;

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

    #[Override]
    public function toValue(): string
    {
        return $this->lexical;
    }

    #[Override]
    public function toCanonicalForm(): string
    {
        return $this->lexical;
    }
}
