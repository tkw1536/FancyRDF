<?php

declare(strict_types=1);

namespace FancySparql\Term;

use DOMDocument;
use DOMNode;
use FancySparql\Xml\XMLUtils;
use InvalidArgumentException;
use Override;

use function filter_var;
use function is_string;
use function str_starts_with;
use function strlen;
use function substr;

use const FILTER_VALIDATE_URL;

/**
 * Represents an RDF resource.
 *
 * @phpstan-type ResourceElement array{'type': 'uri', 'value': string} | array{'type': 'bnode', 'value': string}
 */
final class Resource extends Term
{
    public function __construct(readonly string $uri)
    {
        if (str_starts_with($uri, '_:')) {
            if (strlen($uri) <= 2) {
                throw new InvalidArgumentException('Invalid blank node ID');
            }

            return;
        }

        if (! filter_var($uri, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Invalid URI');
        }
    }

    #[Override]
    public function equals(Term $other): bool
    {
        return $other instanceof Resource && $this->uri === $other->uri;
    }

    /**
     * @param mixed[] $data
     *
     * @throws InvalidArgumentException
     */
    #[Override]
    public static function deserializeJSON(array $data): Resource
    {
        $type = $data['type'] ?? null;
        if ($type !== 'uri' && $type !== 'bnode') {
            throw new InvalidArgumentException('Invalid resource type');
        }

        $value = $data['value'] ?? null;
        if (! is_string($value)) {
            throw new InvalidArgumentException('Invalid resource value');
        }

        if ($type === 'bnode') {
            $value = '_:' . $value;
        }

        return new Resource($value);
    }

    /** @throws InvalidArgumentException */
    #[Override]
    public static function deserializeXML(DOMNode $element): Resource
    {
        $elementName = $element->localName;
        if ($elementName === 'uri') {
            return new Resource($element->textContent);
        }

        if ($elementName === 'bnode') {
            return new Resource('_:' . $element->textContent);
        }

        throw new InvalidArgumentException('Invalid element name');
    }

    /**
     * @return string|null
     *  The blank node ID, or null if this is not a blank node.
     */
    public function getBlankNodeId(): string|null
    {
        if (! str_starts_with($this->uri, '_:')) {
            return null;
        }

        return substr($this->uri, 2);
    }

    /** @return ResourceElement */
    #[Override]
    public function jsonSerialize(): array
    {
        $id = $this->getBlankNodeId();
        if ($id !== null) {
            return [
                'type' => 'bnode',
                'value' => $id,
            ];
        }

        return [
            'type' => 'uri',
            'value' => $this->uri,
        ];
    }

    #[Override]
    public function xmlSerialize(DOMDocument $document): DOMNode
    {
        $blankNodeID = $this->getBlankNodeId();

        return $blankNodeID !== null
            ? XMLUtils::createElement($document, 'bnode', $blankNodeID)
            : XMLUtils::createElement($document, 'uri', $this->uri);
    }
}
