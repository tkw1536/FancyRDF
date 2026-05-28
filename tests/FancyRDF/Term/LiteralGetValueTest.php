<?php

declare(strict_types=1);

namespace FancyRDF\Tests\FancyRDF\Term;

use FancyRDF\Exceptions\InvalidLexicalValueError;
use FancyRDF\Exceptions\UnsupportedLexicalValueError;
use FancyRDF\Term\Datatype\Datatype;
use FancyRDF\Term\Datatype\LangString;
use FancyRDF\Term\Datatype\Unsupported;
use FancyRDF\Term\Datatype\XMLLiteral;
use FancyRDF\Term\Datatype\XSDBoolean;
use FancyRDF\Term\Datatype\XSDString;
use FancyRDF\Term\Iri;
use FancyRDF\Term\Literal;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Throwable;

use function count;

final class LiteralGetValueTest extends TestCase
{
    /**
     * @return array<string, array{
     *   Literal,
     *   class-string<Datatype<mixed>>,
     *   mixed,
     *   class-string<Throwable>|null,
     *   Literal,
     * }>
     *
     * @throws InvalidArgumentException
     */
    public static function getValueProvider(): array
    {
        return [
            'plain XSD string' => [
                Literal::XSDString('hello'),
                XSDString::class,
                'hello',
                null,
                Literal::XSDString('hello'),
            ],
            'language-tagged string' => [
                Literal::langString('bonjour', 'en'),
                LangString::class,
                ['bonjour', 'en'],
                null,
                Literal::langString('bonjour', 'en'),
            ],
            'boolean true lexical' => [
                Literal::typed('true', XSDBoolean::IRI),
                XSDBoolean::class,
                true,
                null,
                Literal::typed('true', XSDBoolean::IRI),
            ],
            'boolean 1 normalizes to true' => [
                Literal::typed('1', XSDBoolean::IRI),
                XSDBoolean::class,
                true,
                null,
                Literal::typed('true', XSDBoolean::IRI),
            ],
            'boolean false lexical' => [
                Literal::typed('false', XSDBoolean::IRI),
                XSDBoolean::class,
                false,
                null,
                Literal::typed('false', XSDBoolean::IRI),
            ],
            'boolean 0 normalizes to false' => [
                Literal::typed('0', XSDBoolean::IRI),
                XSDBoolean::class,
                false,
                null,
                Literal::typed('false', XSDBoolean::IRI),
            ],
            'invalid boolean lexical' => [
                Literal::typed('yes', XSDBoolean::IRI),
                XSDBoolean::class,
                null,
                InvalidLexicalValueError::class,
                Literal::typed('yes', XSDBoolean::IRI),
            ],
            'invalid boolean numeric lexical' => [
                Literal::typed('2', XSDBoolean::IRI),
                XSDBoolean::class,
                null,
                InvalidLexicalValueError::class,
                Literal::typed('2', XSDBoolean::IRI),
            ],
            'unsupported datatype' => [
                Literal::typed('42', 'http://www.w3.org/2001/XMLSchema#integer'),
                Unsupported::class,
                null,
                UnsupportedLexicalValueError::class,
                Literal::typed('42', 'http://www.w3.org/2001/XMLSchema#integer'),
            ],
            'invalid UTF-8 plain string' => [
                new Literal("\xFF", null, new Iri(XSDString::IRI)),
                XSDString::class,
                null,
                InvalidLexicalValueError::class,
                new Literal("\xFF", null, new Iri(XSDString::IRI)),
            ],
            'invalid XML literal' => [
                Literal::typed('not well-formed <', XMLLiteral::IRI),
                XMLLiteral::class,
                null,
                InvalidLexicalValueError::class,
                Literal::typed('not well-formed <', XMLLiteral::IRI),
            ],
        ];
    }

    /**
     * @return array<string, array{list<list<Literal>>}>
     *
     * @throws InvalidArgumentException
     */
    public static function valueEqualLiteralGroupsProvider(): array
    {
        return [
            'supported datatypes' => [
                [
                    [
                        new Literal('hello'),
                        Literal::XSDString('hello'),
                    ],
                    [
                        new Literal('bonjour', 'en'),
                        Literal::langString('bonjour', 'en'),
                    ],
                    [
                        Literal::typed('1', XSDBoolean::IRI),
                        Literal::typed('true', XSDBoolean::IRI),
                    ],
                    [
                        Literal::typed('0', XSDBoolean::IRI),
                        Literal::typed('false', XSDBoolean::IRI),
                    ],
                ],
            ],
        ];
    }

    /**
     * @param class-string<Datatype<mixed>> $expectedDatatypeClass
     * @param class-string<Throwable>|null  $expectedException
     *
     * @throws InvalidArgumentException
     */
    #[DataProvider('getValueProvider')]
    #[TestDox('$_dataname getValue returns the expected datatype, value, and normalization')]
    public function testGetValue(
        Literal $literal,
        string $expectedDatatypeClass,
        mixed $expectedValue,
        string|null $expectedException,
        Literal $normalizedLiteral,
    ): void {
        self::assertInstanceOf($expectedDatatypeClass, $literal->getDatatypeInstance());

        if ($expectedException !== null) {
            $this->expectException($expectedException);
            $literal->getValue();

            return;
        }

        $value = $literal->getValue();
        self::assertSame($expectedValue, $value);

        self::assertTrue(
            $literal->equals($normalizedLiteral, false),
            'literal is value-equal to its normalized form',
        );
        self::assertSame(
            $normalizedLiteral->lexical,
            $literal->getDatatypeInstance()->toCanonicalForm(),
            'normalized literal uses the canonical lexical form',
        );
    }

    /** @param list<list<Literal>> $groups */
    #[DataProvider('valueEqualLiteralGroupsProvider')]
    #[TestDox('value-equal literals are equal within groups and distinct across groups')]
    public function testValueEqualLiteralGroups(array $groups): void
    {
        foreach ($groups as $groupIndex => $group) {
            self::assertGreaterThanOrEqual(2, count($group), 'group ' . $groupIndex . ' must have at least two literals');

            foreach ($group as $i => $left) {
                foreach ($group as $j => $right) {
                    self::assertTrue(
                        $left->equals($right, false),
                        'value equality in group ' . $groupIndex . ': ' . $i . ' vs ' . $j,
                    );
                }
            }

            foreach ($groups as $otherGroupIndex => $otherGroup) {
                if ($otherGroupIndex === $groupIndex) {
                    continue;
                }

                foreach ($group as $i => $left) {
                    foreach ($otherGroup as $j => $right) {
                        self::assertFalse(
                            $left->equals($right, false),
                            'value inequality across groups ' . $groupIndex . ':' . $i . ' vs ' . $otherGroupIndex . ':' . $j,
                        );
                    }
                }
            }
        }
    }
}
