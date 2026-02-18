#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use FancySparql\Formats\NFormatParser;
use FancySparql\Formats\NFormatSerializer;
use FancySparql\Graph\RdfXmlParser;
use FancySparql\Term\Resource;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\StreamWrapper;
use GuzzleHttp\Psr7\Utils;

$stream = NFormatParser::parseStream(Utils::streamFor(fopen('php://stdin', 'r')));

$target = new Resource("http://objekte-im-netz.fau.de/sgs_neu/id/");

foreach ($stream as $quad) {
    $quad[3] ??= $target;
    echo NFormatSerializer::serialize($quad[0], $quad[1], $quad[2], $quad[3]) . "\n";
}
