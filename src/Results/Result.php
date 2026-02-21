<?php

declare(strict_types=1);

namespace FancyRDF\Results;

use DOMDocument;
use DOMElement;
use FancyRDF\Term\Literal;
use FancyRDF\Term\Resource;
use FancyRDF\Xml\XMLSerializable;
use FancyRDF\Xml\XMLUtils;
use InvalidArgumentException;
use IteratorAggregate;
use JsonSerializable;
use Override;
use Traversable;

use function array_key_exists;

/**
 * @phpstan-import-type ResourceElement from Resource
 * @phpstan-import-type LiteralElement from Literal
 * @implements IteratorAggregate<string, Literal|Resource>
 */
final class Result implements JsonSerializable, XMLSerializable, IteratorAggregate
{
    /**
     * The bindings of this result.
     *
     * @var array<string, Literal|Resource>
     */
    private readonly array $bindings;

    /**
     * Constructs a new result from an iterable of bindings.
     *
     * @param iterable<string, Literal|Resource> $bindings
     *
     * @return void
     */
    public function __construct(iterable $bindings)
    {
        $ary = [];
        foreach ($bindings as $name => $binding) {
            $ary[$name] = $binding;
        }

        $this->bindings = $ary;
    }

    /** @return Traversable<string, Literal|Resource> */
    #[Override]
    public function getIterator(): Traversable
    {
        yield from $this->bindings;
    }

    /**
     * Returns the binding for the given name.
     *
     * @return ($allowMissing is true ? Literal|Resource|null : Literal|Resource)
     *
     * @throws InvalidArgumentException
     */
    public function get(string $name, bool $allowMissing = true): Literal|Resource|null
    {
        if (! array_key_exists($name, $this->bindings)) {
            if ($allowMissing) {
                return null;
            }

            throw new InvalidArgumentException("Binding '" . $name . "' not found");
        }

        return $this->bindings[$name];
    }

    /**
     * Returns the binding for the given name, asserting that it is a literal.
     *
     * @return ($allowMissing is true ? Literal|null : Literal)
     *
     * @throws  InvalidArgumentException
     */
    public function getLiteral(string $name, bool $allowMissing = true): Literal|null
    {
        $value = $this->get($name, $allowMissing);
        if ($value !== null && ! $value instanceof Literal) {
            throw new InvalidArgumentException("Binding '" . $name . "' is not a Literal");
        }

        return $value;
    }

    /**
     * Returns the binding for the given name, asserting that it is a resource.
     *
     * @return ($allowMissing is true ? Resource|null : Resource)
     *
     * @throws InvalidArgumentException
     */
    public function getResource(string $name, bool $allowMissing = true): Resource|null
    {
        $value = $this->get($name, $allowMissing);
        if ($value !== null && ! $value instanceof Resource) {
            throw new InvalidArgumentException("Binding '" . $name . "' is not a Resource");
        }

        return $value;
    }

    /** @return array<string, ResourceElement|LiteralElement> */
    #[Override]
    public function jsonSerialize(): array
    {
        $ary = [];
        foreach ($this->bindings as $name => $binding) {
            $ary[$name] = $binding->jsonSerialize();
        }

        return $ary;
    }

    #[Override]
    public function xmlSerialize(DOMDocument $document): DOMElement
    {
        $result = XMLUtils::createElement($document, 'result');
        foreach ($this->bindings as $name => $value) {
            $binding = XMLUtils::createElement($document, 'binding');
            $binding->setAttribute('name', $name);

            $term = $value->xmlSerialize($document);
            $binding->appendChild($term);
            $result->appendChild($binding);
        }

        return $result;
    }
}
