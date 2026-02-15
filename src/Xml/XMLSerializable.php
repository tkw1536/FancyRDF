<?php

declare(strict_types=1);

namespace FancySparql\Xml;

use DOMDocument;
use DOMNode;

interface XMLSerializable
{
    /**
     * Serializes this object to an XML element.
     */
    public function xmlSerialize(DOMDocument $document): DOMNode;
}
