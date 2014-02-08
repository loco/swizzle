#!/usr/bin/env php
<?php
/**
 * Swizzle example.
 * Pulls down the Loco API and outputs the Guzzle JSON service description.
 */

// All we need is the DocsModel class.
require __DIR__.'/vendor/autoload.php';
use Loco\Utils\Swizzle\DocsModel;


// Intialize service with name and description
$service = new DocsModel( 'loco', 'Loco REST API' );

// Register custom Guzzle response classes - such things are meaningless to Swagger.
// These classes don't have have to exist in this runtime, they just go into the service definition.
$raw = '\Loco\Http\Response\RawResponse';
$zip = '\Loco\Http\Response\ZipResponse';
$service->registerResponseClass('exportArchive', $zip )
        ->registerResponseClass('exportLocale', $raw )
        ->registerResponseClass('exportAll', $raw )
        ->registerResponseClass('convert', $raw );

       
// Now we're ready to build from a live endpoint
// This must be a Valid Swagger JSON resource listing.
$service->build('http://localise.biz/api/docs');        


// export service description to JSON:
$json = $service->toJson();
echo $json;