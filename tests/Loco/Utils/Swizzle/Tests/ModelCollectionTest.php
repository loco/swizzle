<?php

namespace Loco\Utils\Swizzle\Tests;

use Loco\Utils\Swizzle\ModelCollection;


/**
 * @group utils
 */
class ModelCollectionTest extends \PHPUnit_Framework_TestCase {

    // define a model that depends on a model
    private static $raw = array (
        'things' => array(
            'type' => 'array',
            'items' => array(
                '$ref' => 'thing',
            ),
        ),
        'thing' => array (
            'type' => 'object',
        ),
    );
    
    
    public function testConstruct(){
        $sorted = new ModelCollection( self::$raw );
        $this->assertCount( 2, $sorted );
        return $sorted;
    }
    
    
    public function testCollectRefs(){
        $refs = ModelCollection::collectRefs( self::$raw );
        $this->assertArrayHasKey('thing', $refs );
    }
    
    
    /**
     * @depends testConstruct
     */
    public function testDependencyOrder( ModelCollection $sorted ){
        // test that the first item is "thing"
        $order = array_values( $sorted->toArray() );
        $this->assertEquals( 'thing', $order[0]['id'] );
        $this->assertEquals( 'things', $order[1]['id'] );
    }
    
    
    
    
}
