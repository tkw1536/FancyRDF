<?php

declare(strict_types=1);

namespace FancyRDF\Xml;

use DOMDocument;
use DOMException;
use DOMNode;
use RuntimeException;

interface XMLSerializable
{
    /**
     * Serializes this object to an XML element.
     *
     * @throws RuntimeException
     * @throws DOMException
     */
    public function xmlSerialize(DOMDocument $document): DOMNode;
}
