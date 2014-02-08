<?php

namespace Loco\Utils\Swizzle\Tests;

use Loco\Utils\Swizzle\Swizzle;
use Guzzle\Service\Description\ServiceDescription;

/**
 * Tests Swizzle
 */
class SwizzleTest extends \PHPUnit_Framework_TestCase {
    
    
    /**
     * @return Swizzle
     */
    public function testInitializeModel(){
        $builder = new Swizzle( 'test', 'A test API', '1.0' );
        $descr = $builder->getServiceDescription();
        $this->assertEquals('test', $descr->getName() );
        $this->assertEquals('1.0', $descr->getApiVersion() );
        return $builder;
    }    


    
    /**
     * Test adding of a model
     * @depends testInitializeModel
     * @return Swizzle
     */    
    public function testModelAddition( Swizzle $builder ){
        // mock a Swagger model definition with one mandatory property    
        $def = array(
            'id' => 'fooType',
            'properties' => array (
                'bar' => array(
                    'type' => 'string',
                    'description' => 'A test property',
                ),
            ),
            'required' => array(
                'bar',
            ),
        );
        $builder->addModel( $def );
        $descr = $builder->getServiceDescription();
        $this->assertCount( 1, $descr->getModels() );
        $foo = $descr->getModel('fooType');
        $this->assertEquals( 'string', $foo->getProperty('bar')->getType() );
        return $builder;
    }
    
    
    
    /**
     * Test adding of an operation with two methods
     * @depends testModelAddition
     * @return Swizzle
     */    
    public function testOperationAddition( Swizzle $builder ){
        // mock a Swagger API with two ops
        $api = array (
            'path' => '/test',
            'operations' => array(
                array (
                    'nickname' => 'getTest',
                    'summary' => 'Gets a test',
                    'method' => 'GET',
                    'type' => 'string',
                ),
                array (
                    'method' => 'PUT',
                    'summary' => 'Puts a test',
                ),
            ),
        );
        $builder->addApi( $api );
        $descr = $builder->getServiceDescription();
        $ops = $descr->getOperations();
        $this->assertCount( 2, $ops, 'Wrong number of operations found' );
        // test specified command name:
        $this->assertArrayHasKey( 'getTest', $ops );
        // test auto-geneated command name:
        $this->assertArrayHasKey( 'put_test', $ops );
        return $builder;
    }    
    
    
    
    /**
     * Test operation parameters
     * @depends testOperationAddition
     * @return Swizzle
     */    
    public function testOperationParameters( Swizzle $builder ){
        // mock a Swagger API op with params 
        $api = array (
            'path' => '/test2',
            'operations' => array (
                array (
                    'parameters' => array (
                        array ( 
                            'name' => 'foo',
                            'paramType' => 'path',
                            'defaultValue' => 'bar',
                            'type' => 'string',
                        )
                    )
                ),
            ),
        );
        $builder->addApi( $api );
        $descr = $builder->getServiceDescription();    
        $op = $descr->getOperation('get_test2');
        /* @var $param Guzzle\Service\Description\Parameter */
        $param = $op->getParam('foo');
        $this->assertEquals( 'uri', $param->getLocation() );
        $this->assertEquals( 'bar', $param->getDefault() );
        $this->assertTrue( $param->getRequired() );
        return $builder;
    }




}








