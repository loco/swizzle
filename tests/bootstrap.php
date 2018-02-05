<?php
/**
 * PHPUnit bootstrap for Swizzle tests.
 */

if ( ! file_exists(dirname(__DIR__).'/vendor') ) {
    die("\nDependencies must be installed using composer:\nSee http://getcomposer.org\n\n");
}

require_once dirname(__DIR__).'/vendor/autoload.php';

