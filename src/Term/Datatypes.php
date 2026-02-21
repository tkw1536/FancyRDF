<?php

declare(strict_types=1);

namespace FancyRDF\Term;

use FancyRDF\Term\Datatype\Datatype;
use FancyRDF\Term\Datatype\LangString;
use FancyRDF\Term\Datatype\XMLLiteral;
use FancyRDF\Term\Datatype\XSDString;
use RuntimeException;

use function sprintf;

final class Datatypes
{
    /** @var array<string, class-string<Datatype<mixed>>> */
    private static array $dataClasses = [];

    /**
     * Registers a new datatype.
     *
     * @param class-string<Datatype<mixed>> $class
     *   The class to register.
     */
    private static function register(string $class): void
    {
        foreach ($class::getIRIs() as $iri) {
            if (isset(self::$dataClasses[$iri])) {
                throw new RuntimeException(sprintf('Datatype IRI %s is already registered', $iri));
            }

            self::$dataClasses[$iri] = $class;
        }
    }

    private static function registerAll(): void
    {
        if (! empty(self::$dataClasses)) {
            return;
        }

        self::register(XSDString::class);
        self::register(LangString::class);
        self::register(XMLLiteral::class);
    }

    /** @return Datatype<mixed> */
    public static function getDatatype(string $iri, string $lexical, string|null $language = null): Datatype
    {
        self::registerAll();

        $class = self::$dataClasses[$iri] ?? XSDString::class;

        return new $class($lexical, $language);
    }
}
