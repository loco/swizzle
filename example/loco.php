#!/usr/bin/env php
<?php
/**
 * Swizzle example.
 * Pulls down the Loco API and outputs the Guzzle service description.
 */

// All we need is the Swizzle class.
require __DIR__.'/../vendor/autoload.php';
use Loco\Utils\Swizzle\Swizzle;

// Intialize service with name and description
$builder = new Swizzle( 'loco', 'Loco REST API' );

// show progress messages in output
$builder->verbose( STDERR );

// Register custom Guzzle response classes - such things are meaningless to Swagger.
// These classes don't have have to exist in this runtime, they just go into the service definition.
$builder->registerResponseClass('exportArchive', '\Loco\Http\Response\ZipResponse' );
       
// Now we're ready to build from a live endpoint
// This must be a Valid Swagger JSON resource listing.
$builder->build('https://localise.biz/api/swagger');        

// export service description to JSON:
echo $builder->toJson();
