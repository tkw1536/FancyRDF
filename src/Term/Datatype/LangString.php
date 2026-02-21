<?php

declare(strict_types=1);

namespace FancyRDF\Term\Datatype;

use Override;

use function assert;

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
        assert($this->language !== null, 'language is required');

        return [$this->lexical, $this->language];
    }

    #[Override]
    public function toCanonicalForm(): string
    {
        return $this->lexical;
    }
}
