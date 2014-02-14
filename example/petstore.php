#!/usr/bin/env php
<?php
/**
 * Swizzle example.
 * Pulls down the Official Swagger example API and outputs the Guzzle service description.
 * @see http://petstore.swagger.wordnik.com/
 */

// All we need is the Swizzle class.
require __DIR__.'/../vendor/autoload.php';
use Loco\Utils\Swizzle\Swizzle;

// Intialize service with name and description
$builder = new Swizzle( 'pets', 'Swagger Pet Store' );

// show progress messages in output
$builder->verbose( STDERR );
       
// Now we're ready to build from a live endpoint
// This must be a Valid Swagger JSON resource listing.
$builder->build('http://petstore.swagger.wordnik.com/api/api-docs');        

// export service description to JSON:
echo $builder->toJson();
