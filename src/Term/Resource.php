<?php

declare(strict_types=1);

namespace FancySparql\Term;

use FancySparql\Xml\XMLUtils;
use InvalidArgumentException;
use Override;
use SimpleXMLElement;

use function filter_var;
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
    public function xmlSerialize(SimpleXMLElement|null $parent = null): SimpleXMLElement
    {
        $blankNodeID = $this->getBlankNodeId();

        return $blankNodeID !== null
            ? XMLUtils::addChild($parent, 'bnode', $blankNodeID)
            : XMLUtils::addChild($parent, 'uri', $this->uri);
    }
}
