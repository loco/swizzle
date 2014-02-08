<?php
/**
 * PHPUnit bootstrap for Swizzle tests.
 */

if ( ! file_exists(dirname(__DIR__).'/vendor') ) {
    die("\nDependencies must be installed using composer:\nSee http://getcomposer.org\n\n");
}

$loader = require_once dirname(__DIR__).'/vendor/autoload.php';
$loader->add('Loco\\Utils\\Swizzle\\Tests', __DIR__ );

