<?php

declare(strict_types=1);

namespace FancyRDF\Term;

use FancyRDF\Term\Datatype\Datatype;
use FancyRDF\Term\Datatype\LangString;
use FancyRDF\Term\Datatype\Unsupported;
use FancyRDF\Term\Datatype\XMLLiteral;
use FancyRDF\Term\Datatype\XSDBoolean;
use FancyRDF\Term\Datatype\XSDString;

use function sprintf;
use function trigger_error;

use const E_USER_WARNING;

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
                trigger_error(sprintf('Datatype IRI %s is already registered', $iri), E_USER_WARNING);
            }

            self::$dataClasses[$iri] = $class;
        }
    }

    private static function registerAll(): void
    {
        if (self::$dataClasses !== []) {
            return;
        }

        self::register(XSDString::class);
        self::register(LangString::class);
        self::register(XSDBoolean::class);
        self::register(XMLLiteral::class);
    }

    /**
     * Create a new specialized datatype instance for a literal with the given iri, lexical, and language.
     *
     * If the datatype is unsupported, an instance of {@see Unsupported} is returned.
     *
     * @return Datatype<mixed> */
    public static function getDatatype(string $iri, string $lexical, string|null $language = null): Datatype
    {
        self::registerAll();

        $class = self::$dataClasses[$iri] ?? Unsupported::class;

        return new $class($lexical, $language, $iri);
    }
}
