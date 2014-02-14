<?php

namespace Loco\Utils\Swizzle\Command;

use Guzzle\Service\Command\OperationResponseParser;
use Guzzle\Service\Command\LocationVisitor\VisitorFlyweight;

/**
 * Response parser that enables schema to be injected into response models.
 */
class StrictResponseParser extends OperationResponseParser {

    /** @var StrictResponseParser */
    protected static $instance;
    
    /**
     * @return StrictResponseParser
     */
    public static function getInstance(){
        if( ! static::$instance ) {
            static::$instance = new StrictResponseParser( VisitorFlyweight::getInstance(), true );
        }
        return static::$instance;
    }
    
    
}
