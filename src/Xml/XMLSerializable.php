<?php

declare(strict_types=1);

namespace FancySparql\Xml;

use SimpleXMLElement;

interface XMLSerializable
{
    /**
     * Serializes this object to an XML element.
     */
    public function xmlSerialize(SimpleXMLElement|null $parent = null): SimpleXMLElement;
}
