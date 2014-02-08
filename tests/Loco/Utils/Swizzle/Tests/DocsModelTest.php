<?php

namespace Loco\Utils\Swizzle\Tests;

use Loco\Utils\Swizzle\DocsModel;
use Guzzle\Service\Description\ServiceDescription;

/**
 * Tests DocsModel
 */
class DocsModelTest extends \PHPUnit_Framework_TestCase {
    
    
    /**
     * @return DocsModel
     */
    public function testInitializeModel(){
        $model = new DocsModel( 'test', 'A test API', '1.0' );
        $descr = $model->getDescription();
        $this->assertEquals('test', $descr->getName() );
        $this->assertEquals('1.0', $descr->getApiVersion() );
        return $model;
    }    


    
    /**
     * Test adding of a model
     * @depends testInitializeModel
     * @return DocsModel
     */    
    public function testModelAddition( DocsModel $model ){
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
        $model->addModel( $def );
        $descr = $model->getDescription();
        $this->assertCount( 1, $descr->getModels() );
        $foo = $descr->getModel('fooType');
        $this->assertEquals( 'string', $foo->getProperty('bar')->getType() );
        return $model;
    }
    
    
    
    /**
     * Test adding of an operation with two methods
     * @depends testModelAddition
     * @return DocsModel
     */    
    public function testOperationAddition( DocsModel $model ){
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
        $model->addSwaggerApi( $api );
        $descr = $model->getDescription();
        $ops = $descr->getOperations();
        $this->assertCount( 2, $ops, 'Wrong number of operations found' );
        // name specified 
        $this->assertArrayHasKey( 'getTest', $ops );
        // name generated
        $this->assertArrayHasKey( 'PUT_test', $ops );
        return $model;
    }    
    
    
    
    /**
     * Test operation parameters
     * @depends testOperationAddition
     * @return DocsModel
     */    
    public function testOperationParameters( DocsModel $model ){
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
        $model->addSwaggerApi( $api );
        $descr = $model->getDescription();    
        $op = $descr->getOperation('GET_test2');
        /* @var $param Guzzle\Service\Description\Parameter */
        $param = $op->getParam('foo');
        $this->assertEquals( 'uri', $param->getLocation() );
        $this->assertEquals( 'bar', $param->getDefault() );
        $this->assertTrue( $param->getRequired() );
        return $model;
    }




}








