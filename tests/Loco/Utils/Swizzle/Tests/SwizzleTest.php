<?php

namespace Loco\Utils\Swizzle\Tests;

use Loco\Utils\Swizzle\Swizzle;
use Guzzle\Service\Description\ServiceDescription;

/**
 * Tests Swizzle
 * @group transform
 */
class SwizzleTest extends \PHPUnit_Framework_TestCase {
    
    
    /**
     * @return Swizzle
     */
    public function testServiceConstruct(){
        $builder = new Swizzle( 'test', 'A test API', '1.0' );
        $descr = $builder->getServiceDescription();
        $this->assertEquals('test', $descr->getName() );
        $this->assertEquals('1.0', $descr->getApiVersion() );
        return $builder;
    }    


    
    /**
     * Test adding of a model
     * @depends testServiceConstruct
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
     * Test a property can inherit an existing model by reference
     * @depends testServiceConstruct
     */
    public function testModelResolvesReference( Swizzle $builder ){
        $child = array (
            'id' => 'barType',
            'type' => 'object',
        );
        $parent = array (
            'id' => 'bazType',
            'type' => 'object',
            'properties' => array (
                'bar' => array(
                    '$ref' => 'barType',
                ),
            ),
        );
        $builder->addModel( $child );
        $builder->addModel( $parent );
        $descr = $builder->getServiceDescription();
        $baz = $descr->getModel('bazType');
        $this->assertEquals( 'object', $baz->getProperty('bar')->getType() );
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
            'path' => '/test/params',
            'operations' => array (
                array (
                    'parameters' => array (
                        // simple path parameter
                        array ( 
                            'name' => 'test',
                            'paramType' => 'path',
                            'defaultValue' => 'ok',
                            'type' => 'string',
                        ),
                        // model parameter containing $foo sent in request body
                        array ( 
                            'name' => 'myFoo',
                            'type' => 'fooType',
                            'paramType' => 'body',
                        ),
                        // object literal containing $bar will get merged in with model
                        array (
                            'name' => 'myBar',
                            'type' => 'object',
                            'paramType' => 'body',
                            'properties' => array(
                                array( 'type' => 'string', 'name' => 'baz' ),
                            ),
                        ),
                        // deliberately add a parameter that conflicts with request body property
                        array (
                            'name' => 'bar',
                            'type' => 'string',
                            'paramType' => 'query',
                        ),
                    )
                ),
            ),
        );
        $builder->addApi( $api );
        $descr = $builder->getServiceDescription();    
        $op = $descr->getOperation('get_test_params');
        /* @var $param Guzzle\Service\Description\Parameter */
        $param = $op->getParam('test');
        $this->assertInstanceOf( '\Guzzle\Service\Description\Parameter', $param );
        $this->assertEquals( 'uri', $param->getLocation() );
        $this->assertEquals( 'ok', $param->getDefault() );
        $this->assertEquals( 'string', $param->getType() );
        $this->assertTrue( $param->getRequired() );
        // The barType request body should have its properties merged into root
        $param = $op->getParam('myBar');
        $this->assertNull( $param );
        $child = $op->getParam('baz');
        $this->assertInstanceOf( '\Guzzle\Service\Description\Parameter', $child );
        $this->assertEquals( 'json', $child->getLocation() );
        // Conflicting bar query param would be reached before conflicting with request body
        // @todo why?
        $param = $op->getParam('bar');
        $this->assertInstanceOf( '\Guzzle\Service\Description\Parameter', $param );
        $this->assertEquals( 'query', $param->getLocation() );
        // The fooType request body  should have resolved to an object too
        $param = $op->getParam('myFoo');
        $this->assertNull( $param );
        // We will need to use *_json namespace to access it as it's conflicted.
        $child = $op->getParam('bar_json');
        $this->assertInstanceOf( '\Guzzle\Service\Description\Parameter', $child );
        $this->assertEquals( 'json', $child->getLocation() );

        return $builder;
    }
    

    
    /**
     * Test an operation that responds with the fooType model we added earlier
     * @depends testModelAddition
     */
    public function testModelResponse( Swizzle $builder ){
        // mock a Swagger API op that returns a fooType
        $api = array (
            'path' => '/test/type',
            'operations' => array (
                array (
                    'type' => 'fooType',
                ),
            ),
        );
        $builder->addApi( $api );
        $descr = $builder->getServiceDescription();    
        $op = $descr->getOperation('get_test_type');
        $this->assertEquals( 'model', $op->getResponseType() );
        $this->assertEquals( 'fooType', $op->getResponseClass() );
    }
    

    
    /**
     * Test an operation that responds with a root object literal
     * @depends testOperationAddition
     */
    public function testObjectLiteralTranformsToModel( Swizzle $builder ){
        // mock a Swagger API op that returns a root type that is defined inline
        $api = array (
            'path' => '/test/type_literal',
            'operations' => array (
                array (
                    'type' => 'object',
                    'properties' => array (
                        'ok' => array (
                            'type' => 'boolean',
                            'defaultValue' => true,
                        ),
                    ),
                ),
            ),
        );
        $builder->addApi( $api );
        $descr = $builder->getServiceDescription();    
        $op = $descr->getOperation('get_test_type_literal');
        $this->assertEquals( 'model', $op->getResponseType() );
        $this->assertStringStartsWith( 'anon_', $op->getResponseClass() );
    }
    

    
    /**
     * Test an operation that responds with an array of fooType objects
     * @depends testModelAddition
     */
    public function testModelArrayResponse( Swizzle $builder ){
        $api = array (
            'path' => '/test/type_array',
            'operations' => array (
                array (
                    'type' => 'array',
                    'items' => array (
                      '$ref' => 'fooType',  
                    ),
                ),
            ),
        );
        $builder->addApi( $api );
        $descr = $builder->getServiceDescription();    
        $op = $descr->getOperation('get_test_type_array');
        // array-based model should have been created on the fly
        // $this->assertEquals( 'model', $op->getResponseType() );
        // $this->assertEquals( 'fooType_array', $op->getResponseClass() );
        // root array modelling disabled - will be unvalidated primitive
        $this->assertEquals( 'primitive', $op->getResponseType() );
        $this->assertEquals( 'array', $op->getResponseClass() );
    }
    
    
    
    /**
     * Test an operation that responds with an array of primatives
     * @depends testServiceConstruct
     */
    public function testDynamicArrayResponse( Swizzle $builder ){
        $api = array (
            'path' => '/test/type_ints',
            'operations' => array (
                array (
                    'type' => 'array',
                    'items' => array (
                        'type' => 'integer', 
                    ),
                ),
            ),
        );
        $builder->addApi( $api );
        $descr = $builder->getServiceDescription();    
        $op = $descr->getOperation('get_test_type_ints');
        // anonymous model should have been created on the fly
        // $this->assertEquals( 'model', $op->getResponseType() );
        // $this->assertEquals( 'anon_type_integer_array', $op->getResponseClass() );
        // root array modelling disabled - will be unvalidated primitive
        $this->assertEquals( 'primitive', $op->getResponseType() );
        $this->assertEquals( 'array', $op->getResponseClass() );
        
    }     
    



    /**
     * Test failure when response type falls back to an unregistered class
     * @depends testServiceConstruct
     * @expectedException \Exception
     */
    public function testUnregisteredResponseClassFails( Swizzle $builder ){
        // mock an operation with a bad response class
        $api = array(
            'path' => '/test/bad_class',
            'operations' => array(
                array(
                    'type' => 'BadClass',
                ),
            ),
        );
        $builder->addApi( $api );
    }



}








