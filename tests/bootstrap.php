<?php
/**
 * PHPUnit bootstrap for Swizzle tests.
 */

if ( ! file_exists(dirname(__DIR__).'/vendor') ) {
    die("\nDependencies must be installed using composer:\nSee http://getcomposer.org\n\n");
}

$loader = require_once dirname(__DIR__).'/vendor/autoload.php';
$loader->setPsr4('Loco\\Tests\\Utils\\Swizzle\\', __DIR__.'/Tests' );

