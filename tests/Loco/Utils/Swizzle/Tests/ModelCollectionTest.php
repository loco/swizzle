<?php

namespace Loco\Utils\Swizzle\Tests;

use Loco\Utils\Swizzle\ModelCollection;


/**
 * @group utils
 */
class ModelCollectionTest extends \PHPUnit_Framework_TestCase {

    // define a model that depends on a model
    private static $raw = array (
        'foo' => array(),
        'baz' => array(
            'items' => array(
                '$ref' => 'bar',
            ),
        ),
        'bar' => array(
            'items' => array(
                '$ref' => 'foo',
            ),
        ),
    );
    
    
    public function testConstruct(){
        $sorted = new ModelCollection( self::$raw );
        $this->assertCount( 3, $sorted );
        return $sorted;
    }
    
    
    public function testCollectRefs(){
        $refs = ModelCollection::collectRefs( self::$raw['bar'] );
        $this->assertArrayHasKey('foo', $refs );
    }
    
    
    /**
     * @depends testConstruct
     */
    public function testDependencyOrder( ModelCollection $sorted ){
        $order = $sorted->getKeys();
        // test that "foo" comes before "bar"
        $lower = array_search('foo',$order);
        $higher = array_search('bar',$order);
        $this->assertGreaterThan( $lower, $higher, '"foo" index expected to be lower than "bar"' );
        // test that "bar" comes before "baz"
        $lower = array_search('bar',$order);
        $higher = array_search('baz',$order);
        $this->assertGreaterThan( $lower, $higher, '"bar" index expected to be lower than "baz"' );
    }
    
    
    
    
}
